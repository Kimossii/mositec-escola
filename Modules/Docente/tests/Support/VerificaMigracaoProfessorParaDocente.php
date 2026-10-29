<?php

namespace Modules\Docente\Tests\Support;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Models\Docente;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\User;
use RuntimeException;

/**
 * Migração `2026_10_29_110000_troca_professor_por_docente_em_atribuicoes`. O esquema antigo obtém-se correndo o
 * `down()` da própria migração (com a tabela vazia) e as atribuições antigas inserem-se directamente por
 * `professor_id`, como estavam na base de desenvolvimento; depois corre-se o `up()`. Partilhado pelas versões SQLite
 * e PostgreSQL do teste. Requer `CenarioDeDocencia` e o contexto de tenant de teste.
 */
trait VerificaMigracaoProfessorParaDocente
{
    private function migracao(): Migration
    {
        return require base_path('Modules/Docente/database/migrations/2026_10_29_110000_troca_professor_por_docente_em_atribuicoes.php');
    }

    /** Linha no esquema antigo (antes da migração), com `professor_id` a apontar para o utilizador. */
    private function atribuicaoAntiga(array $c, int $utilizadorId, int $disciplinaId, array $extra = []): int
    {
        return (int) DB::table('atribuicoes_docentes')->insertGetId(array_merge([
            'tenant_id' => $this->tenant->id,
            'ano_lectivo_id' => $c['ano']->id,
            'turma_id' => $c['turma']->id,
            'disciplina_id' => $disciplinaId,
            'professor_id' => $utilizadorId,
            'papel' => PapelDocente::SUBSTITUTO->value,
            'papel_descricao' => 'Substituto',
            'data_inicio' => '2026-03-01',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ], $extra));
    }

    public function test_migracao_preenche_docentes_para_professores_ja_atribuidos(): void
    {
        $c = $this->cenarioDeDocencia();
        // 1) Conta sem pessoa: ganha uma DadosPessoa feita do nome e email da conta.
        $semPessoa = $this->criarProfessor('sem-pessoa@example.com');
        // 2) Conta cuja pessoa já é docente: reutiliza esse docente.
        $docenteExistente = $this->criarDocente('Docente Já Registado', comAcesso: true);
        $comDocente = $docenteExistente->utilizador();
        // 3) Conta com pessoa mas sem docente: o docente fica com a pessoa da conta.
        $pessoa = DadosPessoa::create(['nome_completo' => 'Pessoa Sem Docente', 'numero_identificacao' => 'BI-PESSOA-1', 'tipo_pessoa' => DadosPessoa::TIPO_PROFESSOR]);
        $comPessoa = $this->criarProfessor('com-pessoa@example.com');
        $comPessoa->update(['dados_pessoa_id' => $pessoa->id]);
        // 4) Conta inactiva: o docente herda o estado.
        $inactivo = $this->criarProfessor('inactivo@example.com');
        $inactivo->update(['estado' => 0]);

        $this->migracao()->down();
        $this->assertTrue(Schema::hasColumn('atribuicoes_docentes', 'professor_id'));
        $this->assertFalse(Schema::hasColumn('atribuicoes_docentes', 'docente_id'));

        $mat = $c['disciplina']->id;
        $por = $c['outraDisciplina']->id;
        $linhasSemPessoa = [
            $this->atribuicaoAntiga($c, $semPessoa->id, $mat),
            // Eliminada (soft delete): também tem de ser preenchida.
            $this->atribuicaoAntiga($c, $semPessoa->id, $por, ['deleted_at' => Carbon::now()]),
        ];
        $linhaComDocente = $this->atribuicaoAntiga($c, $comDocente->id, $mat, ['data_inicio' => '2026-05-01']);
        $linhaComPessoa = $this->atribuicaoAntiga($c, $comPessoa->id, $mat, ['data_inicio' => '2026-07-01']);
        $linhaInactivo = $this->atribuicaoAntiga($c, $inactivo->id, $mat, ['data_inicio' => '2026-09-01']);

        $this->migracao()->up();

        $this->assertFalse(Schema::hasColumn('atribuicoes_docentes', 'professor_id'));
        $this->assertTrue(Schema::hasColumn('atribuicoes_docentes', 'docente_id'));
        $this->assertSame(0, DB::table('atribuicoes_docentes')->whereNull('docente_id')->count());

        // 1) Pessoa criada a partir da conta e ligada a ela; as duas linhas apontam para o mesmo docente.
        $semPessoa->refresh();
        $this->assertNotNull($semPessoa->dados_pessoa_id);
        $novaPessoa = DadosPessoa::findOrFail($semPessoa->dados_pessoa_id);
        $this->assertSame('Professor sem-pessoa@example.com', $novaPessoa->nome_completo);
        $this->assertSame('sem-pessoa@example.com', $novaPessoa->email);
        $this->assertSame(DadosPessoa::TIPO_PROFESSOR, (int) $novaPessoa->tipo_pessoa);
        $this->assertSame($this->tenant->id, (int) $novaPessoa->tenant_id);
        $docenteSemPessoa = Docente::query()->where('dados_pessoa_id', $novaPessoa->id)->firstOrFail();
        foreach ($linhasSemPessoa as $id) {
            $this->assertSame($docenteSemPessoa->id, (int) DB::table('atribuicoes_docentes')->where('id', $id)->value('docente_id'));
        }
        $this->assertSame($semPessoa->id, $docenteSemPessoa->utilizador()->id);
        $this->assertSame(Estabelecimento::current()->id, (int) $docenteSemPessoa->estabelecimento_id);
        $this->assertSame(1, $docenteSemPessoa->estado);
        $this->assertSame('Ativo', $docenteSemPessoa->estado_descricao);

        // 2) Docente já existente reutilizado.
        $this->assertSame($docenteExistente->id, AtribuicaoDocente::query()->findOrFail($linhaComDocente)->docente_id);

        // 3) A pessoa do docente é a da conta.
        $docenteComPessoa = AtribuicaoDocente::query()->findOrFail($linhaComPessoa)->docente;
        $this->assertSame($pessoa->id, $docenteComPessoa->dados_pessoa_id);
        $this->assertSame('Pessoa Sem Docente', $docenteComPessoa->dadosPessoa->nome_completo);

        // 4) Estado herdado.
        $docenteInactivo = AtribuicaoDocente::query()->findOrFail($linhaInactivo)->docente;
        $this->assertSame(0, $docenteInactivo->estado);
        $this->assertSame('Inativo', $docenteInactivo->estado_descricao);

        // Um docente por pessoa, nenhum duplicado.
        $this->assertSame(4, Docente::query()->count());
    }

    public function test_migracao_reversivel_e_idempotente_para_docentes_com_conta(): void
    {
        $c = $this->cenarioDeDocencia();
        $docente = $this->criarDocente('Docente Com Conta', comAcesso: true);
        $atribuicao = AtribuicaoDocente::create([
            'ano_lectivo_id' => $c['ano']->id, 'turma_id' => $c['turma']->id, 'disciplina_id' => $c['disciplina']->id,
            'docente_id' => $docente->id, 'papel' => PapelDocente::TITULAR, 'data_inicio' => '2026-03-01',
        ]);

        $pessoasAntes = DadosPessoa::query()->count();

        $this->migracao()->down();

        $this->assertSame($docente->utilizador()->id, (int) DB::table('atribuicoes_docentes')->where('id', $atribuicao->id)->value('professor_id'));

        // De novo para cima: reutiliza o mesmo docente (nada duplicado).
        $this->migracao()->up();

        $this->assertSame($docente->id, $atribuicao->fresh()->docente_id);
        $this->assertSame(1, Docente::query()->count());
        $this->assertSame($pessoasAntes, DadosPessoa::query()->count());
    }

    public function test_migracao_down_falha_se_algum_docente_atribuido_nao_tiver_conta(): void
    {
        $c = $this->cenarioDeDocencia();
        $docente = $this->criarDocente('Docente Sem Conta');
        AtribuicaoDocente::create([
            'ano_lectivo_id' => $c['ano']->id, 'turma_id' => $c['turma']->id, 'disciplina_id' => $c['disciplina']->id,
            'docente_id' => $docente->id, 'papel' => PapelDocente::TITULAR, 'data_inicio' => '2026-03-01',
        ]);

        try {
            $this->migracao()->down();
            $this->fail('O down() devia falhar com um docente atribuído sem conta.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('não têm conta de utilizador', $e->getMessage());
            $this->assertStringContainsString((string) $docente->id, $e->getMessage());
        }

        // Nada mudou no esquema.
        $this->assertTrue(Schema::hasColumn('atribuicoes_docentes', 'docente_id'));
        $this->assertFalse(Schema::hasColumn('atribuicoes_docentes', 'professor_id'));
    }

    public function test_migracao_recusa_pessoa_de_outro_tenant_e_nao_altera_nada(): void
    {
        $c = $this->cenarioDeDocencia();
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $pessoaAlheia = $this->noTenant($outro, fn () => DadosPessoa::create(['nome_completo' => 'Alheia', 'numero_identificacao' => 'BI-ALHEIA', 'tipo_pessoa' => DadosPessoa::TIPO_PROFESSOR]));
        $professor = $this->criarProfessor('cruzado@example.com');
        $professor->update(['dados_pessoa_id' => $pessoaAlheia->id]);

        $this->migracao()->down();
        $linha = $this->atribuicaoAntiga($c, $professor->id, $c['disciplina']->id);
        $docentesAntes = DB::table('docentes')->count();

        try {
            $this->migracao()->up();
            $this->fail('A migração devia recusar a pessoa de outro tenant.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('outro tenant', $e->getMessage());
        }

        $this->assertSame($docentesAntes, DB::table('docentes')->count());
        $this->assertTrue(Schema::hasColumn('atribuicoes_docentes', 'professor_id'));
        $this->assertSame($professor->id, (int) DB::table('atribuicoes_docentes')->where('id', $linha)->value('professor_id'));
    }
}
