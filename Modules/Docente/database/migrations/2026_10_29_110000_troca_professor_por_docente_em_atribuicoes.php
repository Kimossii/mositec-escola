<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A atribuição passa a apontar para o docente (a pessoa da escola, com ou sem conta) em vez do utilizador.
 *
 * Fase 1: para cada utilizador já atribuído garante um docente (idempotente: reutiliza o docente da mesma
 * `dados_pessoa_id`; um utilizador sem pessoa ganha uma `dados_pessoas` feita do nome e email da conta) e preenche
 * `docente_id`. Fase 2: troca a coluna, a FK composta e o índice e, em PostgreSQL, a exclusão do «mesmo professor».
 *
 * Só query builder: os models (e os seus scopes de tenant) podem mudar depois desta migração; o tenant de cada
 * linha é sempre o `tenant_id` explícito da própria linha.
 */
return new class extends Migration
{
    private const TABELA = 'atribuicoes_docentes';

    private const EXCLUSAO_PROFESSOR = 'atribuicoes_docentes_professor_excl';

    private const EXCLUSAO_DOCENTE = 'atribuicoes_docentes_docente_excl';

    private const INDICE_PROFESSOR = 'atribuicoes_docentes_professor_fim_index';

    private const INDICE_DOCENTE = 'atribuicoes_docentes_docente_fim_index';

    /** `DadosPessoa::TIPO_PROFESSOR` (valor fixo aqui: a migração não depende do model). */
    private const TIPO_PESSOA_PROFESSOR = 1;

    public function up(): void
    {
        Schema::table(self::TABELA, function (Blueprint $table) {
            $table->unsignedBigInteger('docente_id')->nullable();
        });

        $this->preencherDocentes();

        if ($this->emPostgres()) {
            DB::statement('ALTER TABLE ' . self::TABELA . ' DROP CONSTRAINT IF EXISTS ' . self::EXCLUSAO_PROFESSOR);
        }

        Schema::table(self::TABELA, function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'professor_id']);
            $table->dropIndex(self::INDICE_PROFESSOR);
        });

        Schema::table(self::TABELA, function (Blueprint $table) {
            $table->dropColumn('professor_id');
        });

        Schema::table(self::TABELA, function (Blueprint $table) {
            $table->unsignedBigInteger('docente_id')->nullable(false)->change();
            // Referência composta: uma atribuição nunca aponta para um docente de outro tenant.
            $table->foreign(['tenant_id', 'docente_id'])->references(['tenant_id', 'id'])->on('docentes')->restrictOnDelete();
            $table->index(['docente_id', 'data_fim'], self::INDICE_DOCENTE);
        });

        if ($this->emPostgres()) {
            // O mesmo docente sem vigências sobrepostas na mesma (turma, disciplina), seja qual for o papel.
            // A do titular (`atribuicoes_docentes_um_titular_excl`) não usa o professor e mantém-se.
            $this->criarExclusao(self::EXCLUSAO_DOCENTE, 'docente_id');
        }
    }

    public function down(): void
    {
        $this->exigirContaParaTodosOsDocentesAtribuidos();

        Schema::table(self::TABELA, function (Blueprint $table) {
            $table->unsignedBigInteger('professor_id')->nullable();
        });

        $this->restaurarProfessores();

        if ($this->emPostgres()) {
            DB::statement('ALTER TABLE ' . self::TABELA . ' DROP CONSTRAINT IF EXISTS ' . self::EXCLUSAO_DOCENTE);
        }

        Schema::table(self::TABELA, function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'docente_id']);
            $table->dropIndex(self::INDICE_DOCENTE);
        });

        Schema::table(self::TABELA, function (Blueprint $table) {
            $table->dropColumn('docente_id');
        });

        Schema::table(self::TABELA, function (Blueprint $table) {
            $table->unsignedBigInteger('professor_id')->nullable(false)->change();
            $table->foreign(['tenant_id', 'professor_id'])->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
            $table->index(['professor_id', 'data_fim'], self::INDICE_PROFESSOR);
        });

        if ($this->emPostgres()) {
            $this->criarExclusao(self::EXCLUSAO_PROFESSOR, 'professor_id');
        }

        // Os docentes e as pessoas criados no `up()` ficam: são dados válidos por si e a tabela `docentes` é de
        // outra migração.
    }

    /** Fase 1 do `up()`: um docente por utilizador atribuído (incluindo atribuições eliminadas por soft delete). */
    private function preencherDocentes(): void
    {
        $professores = DB::table(self::TABELA)
            ->select('tenant_id', 'professor_id')
            ->distinct()
            ->orderBy('tenant_id')
            ->orderBy('professor_id')
            ->get();

        foreach ($professores as $linha) {
            $tenantId = (int) $linha->tenant_id;
            $utilizadorId = (int) $linha->professor_id;

            DB::table(self::TABELA)
                ->where('tenant_id', $tenantId)
                ->where('professor_id', $utilizadorId)
                ->update(['docente_id' => $this->docenteDoUtilizador($tenantId, $utilizadorId)]);
        }
    }

    private function docenteDoUtilizador(int $tenantId, int $utilizadorId): int
    {
        $utilizador = DB::table('users')->where('tenant_id', $tenantId)->where('id', $utilizadorId)->first();

        if ($utilizador === null) {
            throw new RuntimeException("Atribuição docente aponta para o utilizador {$utilizadorId}, que não existe no tenant {$tenantId}.");
        }

        if ($utilizador->dados_pessoa_id !== null) {
            $pessoaTenant = DB::table('dados_pessoas')->where('id', $utilizador->dados_pessoa_id)->value('tenant_id');

            if ($pessoaTenant === null || (int) $pessoaTenant !== $tenantId) {
                throw new RuntimeException("A pessoa {$utilizador->dados_pessoa_id} do utilizador {$utilizadorId} pertence a outro tenant ou não existe; corrija a ligação antes de migrar (tenant {$tenantId}).");
            }
        }

        $pessoaId = $utilizador->dados_pessoa_id !== null
            ? (int) $utilizador->dados_pessoa_id
            : $this->criarPessoaDoUtilizador($utilizador);

        $existente = DB::table('docentes')->where('dados_pessoa_id', $pessoaId)->first();

        if ($existente !== null) {
            if ((int) $existente->tenant_id !== $tenantId) {
                throw new RuntimeException("A pessoa {$pessoaId} do utilizador {$utilizadorId} é docente noutro tenant ({$existente->tenant_id}).");
            }

            return (int) $existente->id;
        }

        $estabelecimentoId = DB::table('estabelecimentos')->where('tenant_id', $tenantId)->orderBy('id')->value('id');

        if ($estabelecimentoId === null) {
            throw new RuntimeException("O tenant {$tenantId} não tem estabelecimento: não é possível criar o docente do utilizador {$utilizadorId}.");
        }

        // O docente herda o estado da conta (um professor inactivo continua a não receber atribuições novas).
        $activo = (int) $utilizador->estado === 1;
        $agora = Carbon::now();

        return (int) DB::table('docentes')->insertGetId([
            'tenant_id' => $tenantId,
            'estabelecimento_id' => (int) $estabelecimentoId,
            'dados_pessoa_id' => $pessoaId,
            'estado' => $activo ? 1 : 0,
            'estado_descricao' => $activo ? 'Ativo' : 'Inativo',
            'created_at' => $agora,
            'updated_at' => $agora,
        ]);
    }

    /**
     * Pessoa feita a partir da conta (nome e email) e ligada ao utilizador. O número de identificação é
     * obrigatório e único por tenant: fica um provisório, a corrigir no cadastro do docente.
     */
    private function criarPessoaDoUtilizador(object $utilizador): int
    {
        $agora = Carbon::now();

        $pessoaId = (int) DB::table('dados_pessoas')->insertGetId([
            'tenant_id' => (int) $utilizador->tenant_id,
            'nome_completo' => $utilizador->name,
            'email' => $utilizador->email,
            'numero_identificacao' => 'SEM-BI-U' . $utilizador->id,
            'tipo_pessoa' => self::TIPO_PESSOA_PROFESSOR,
            'created_at' => $agora,
            'updated_at' => $agora,
        ]);

        DB::table('users')->where('id', $utilizador->id)->update(['dados_pessoa_id' => $pessoaId]);

        return $pessoaId;
    }

    /** `professor_id` exige um utilizador: um docente atribuído sem conta torna a reversão impossível. */
    private function exigirContaParaTodosOsDocentesAtribuidos(): void
    {
        $semConta = DB::table(self::TABELA . ' as a')
            ->join('docentes as d', 'd.id', '=', 'a.docente_id')
            ->leftJoin('users as u', function ($join) {
                $join->on('u.dados_pessoa_id', '=', 'd.dados_pessoa_id')->on('u.tenant_id', '=', 'd.tenant_id');
            })
            ->whereNull('u.id')
            ->distinct()
            ->orderBy('a.docente_id')
            ->pluck('a.docente_id');

        if ($semConta->isNotEmpty()) {
            throw new RuntimeException(
                'Não é possível reverter a troca de professor por docente: os docentes ' . $semConta->implode(', ')
                . ' têm atribuições e não têm conta de utilizador. Crie-lhes acesso ou remova essas atribuições antes de reverter.'
            );
        }
    }

    private function restaurarProfessores(): void
    {
        $docentes = DB::table(self::TABELA . ' as a')
            ->join('docentes as d', 'd.id', '=', 'a.docente_id')
            ->join('users as u', function ($join) {
                $join->on('u.dados_pessoa_id', '=', 'd.dados_pessoa_id')->on('u.tenant_id', '=', 'd.tenant_id');
            })
            ->select('a.docente_id', 'u.id as utilizador_id')
            ->distinct()
            ->get();

        foreach ($docentes as $linha) {
            DB::table(self::TABELA)->where('docente_id', $linha->docente_id)->update(['professor_id' => $linha->utilizador_id]);
        }
    }

    private function criarExclusao(string $nome, string $coluna): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement(<<<SQL
            ALTER TABLE atribuicoes_docentes ADD CONSTRAINT {$nome}
            EXCLUDE USING gist (
                turma_id WITH =,
                disciplina_id WITH =,
                {$coluna} WITH =,
                (daterange(data_inicio, COALESCE(data_fim, 'infinity'::date), '[]')) WITH &&
            ) WHERE (deleted_at IS NULL)
        SQL);
    }

    private function emPostgres(): bool
    {
        return DB::getDriverName() === 'pgsql';
    }
};
