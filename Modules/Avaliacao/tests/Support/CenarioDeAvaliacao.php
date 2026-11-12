<?php

namespace Modules\Avaliacao\Tests\Support;

use Illuminate\Support\Facades\Hash;
use Modules\Aluno\Models\Aluno;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Avaliacao\Actions\AnularNotaAction;
use Modules\Avaliacao\Actions\DecidirProgressaoAction;
use Modules\Avaliacao\Actions\DefinirPrazoAction;
use Modules\Avaliacao\Actions\GuardarCompetenciasAction;
use Modules\Avaliacao\Actions\GuardarConfigDocumentosAction;
use Modules\Avaliacao\Actions\GuardarEscalaQualitativaAction;
use Modules\Avaliacao\Actions\GuardarRegraQualitativaAction;
use Modules\Avaliacao\Actions\LancarExameAction;
use Modules\Avaliacao\Actions\LancarRecursoAction;
use Modules\Avaliacao\Actions\ProlongarPrazoAction;
use Modules\Avaliacao\DTO\AnularNotaDTO;
use Modules\Avaliacao\DTO\CompetenciaDTO;
use Modules\Avaliacao\DTO\CompetenciasDaAreaDTO;
use Modules\Avaliacao\DTO\ConfigDocumentosDTO;
use Modules\Avaliacao\DTO\DecidirProgressaoDTO;
use Modules\Avaliacao\DTO\DefinirPrazoDTO;
use Modules\Avaliacao\DTO\EscalaQualitativaDTO;
use Modules\Avaliacao\DTO\LancarExameDTO;
use Modules\Avaliacao\DTO\NivelDaEscalaDTO;
use Modules\Avaliacao\DTO\ProlongarPrazoDTO;
use Modules\Avaliacao\DTO\RegraQualitativaDTO;
use Modules\Avaliacao\Enums\Decisao;
use Modules\Avaliacao\Enums\EstadoNota;
use Modules\Avaliacao\Enums\Progressao;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoEscala;
use Modules\Avaliacao\Models\AvaliacaoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoNota;
use Modules\Avaliacao\Models\AvaliacaoPrazo;
use Modules\Avaliacao\Models\AvaliacaoPrazoExcepcao;
use Modules\Avaliacao\Models\AvaliacaoProgressao;
use Modules\Avaliacao\Models\AvaliacaoRegraQualitativa;
use Modules\Curso\Models\Curso;
use Modules\Disciplina\Models\Disciplina;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Models\Docente;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum as EtapaQualitativa;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Matricula\Models\Matricula as MatriculaDoCenario;
use Modules\Matricula\Models\Matricula;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularAnoLectivo;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\User;

/**
 * Cenário base da avaliação no tenant do contexto (corre-se dentro de `noTenant()` para outro tenant):
 * ano 2026 com 3 trimestres, uma classe, uma turma, uma disciplina no plano e um aluno inscrito nela.
 */
trait CenarioDeAvaliacao
{
    private static int $alunosDeAvaliacao = 0;

    /**
     * @return array{ano: AnoLectivo, periodos: array<int, Periodo>, nivel: NivelAcademico, turma: Turma, disciplina: Disciplina, planoDisciplina: PlanoCurricularDisciplina}
     */
    protected function cenarioDeAvaliacao(string $codigoNivel = 'PRI_3', string $marca = 'A'): array
    {
        $estabelecimentoId = Estabelecimento::current()->id;

        $ano = AnoLectivo::create([
            'estabelecimento_id' => $estabelecimentoId, 'nome' => "2026{$marca}", 'data_inicio' => '2026-01-01',
            'data_fim' => '2026-12-31', 'estado' => EstadoAnoLectivo::ATIVO,
        ]);

        $periodos = [];
        foreach ([1 => ['2026-01-01', '2026-04-30'], 2 => ['2026-05-01', '2026-08-31'], 3 => ['2026-09-01', '2026-12-31']] as $n => [$inicio, $fim]) {
            $periodos[$n] = Periodo::create([
                'ano_lectivo_id' => $ano->id, 'nome' => "{$n}.º Trimestre", 'tipo' => TipoPeriodo::TRIMESTRE,
                'numero' => $n, 'data_inicio' => $inicio, 'data_fim' => $fim,
            ]);
        }

        $curso = Curso::create(['estabelecimento_id' => $estabelecimentoId, 'codigo' => "CU{$marca}", 'nome' => "Curso{$marca}"]);
        $nivel = NivelAcademico::create([
            'estabelecimento_id' => $estabelecimentoId, 'codigo' => $codigoNivel, 'nome' => "Classe {$codigoNivel}",
            'etapa_ensino' => EtapaEnsinoEnum::PRIMARIO, 'ordem' => 1,
        ]);
        $turma = Turma::create([
            'ano_lectivo_id' => $ano->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id,
            'codigo' => "TU{$marca}", 'nome' => "Turma{$marca}",
        ]);
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimentoId, 'codigo' => "MAT{$marca}", 'nome' => "Matemática{$marca}"]);
        $plano = PlanoCurricular::create([
            'estabelecimento_id' => $estabelecimentoId, 'curso_id' => $curso->id, 'nivel_academico_id' => $nivel->id,
            'codigo' => "PL{$marca}", 'nome' => "Plano{$marca}",
        ]);
        PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $ano->id]);
        $planoDisciplina = PlanoCurricularDisciplina::create(['plano_curricular_id' => $plano->id, 'disciplina_id' => $disciplina->id]);

        // Os cenários trabalham com uma avaliação contínua por trimestre: o mínimo a lançar é 1 (por defeito, na escola, é 5).
        \Modules\Avaliacao\Models\AvaliacaoDefinicoes::query()->firstOrCreate([], ['max_avaliacoes' => 10, 'min_avaliacoes' => 1]);

        return compact('ano', 'periodos', 'nivel', 'turma', 'disciplina', 'planoDisciplina');
    }

    /** Aluno matriculado na turma do cenário e inscrito na sua disciplina. */
    protected function inscreverAluno(array $cenario, string $nome = 'Aluno'): InscricaoDisciplina
    {
        $n = ++self::$alunosDeAvaliacao;

        $pessoa = DadosPessoa::create(['nome_completo' => "{$nome} {$n}", 'numero_identificacao' => "BI-AV-{$n}", 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $aluno = Aluno::create([
            'estabelecimento_id' => Estabelecimento::current()->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => "2026-AV-{$n}",
        ]);
        $matricula = Matricula::create([
            'aluno_id' => $aluno->id, 'turma_id' => $cenario['turma']->id, 'ano_lectivo_id' => $cenario['ano']->id,
            'numero_registo_matricula' => "REG-AV-{$n}", 'data_matricula' => '2026-02-01',
            'estado' => EstadoMatriculaEnum::ACTIVA->value,
        ]);

        return InscricaoDisciplina::create([
            'matricula_id' => $matricula->id, 'plano_curricular_disciplina_id' => $cenario['planoDisciplina']->id,
            'data_inscricao' => '2026-02-01', 'estado' => EstadoInscricaoDisciplinaEnum::INSCRITA,
        ]);
    }

    /** Utilizador do tenant com o perfil dado (semeia os perfis se ainda não existirem). */
    protected function criarUtilizadorComPerfil(Perfil $perfil, string $email): User
    {
        if (! Role::where('nome', $perfil->value)->exists()) {
            $this->seed(PermissaoDatabaseSeeder::class);
        }

        $user = User::create(['name' => $perfil->name.' '.$email, 'email' => $email, 'password' => Hash::make('segredo123')]);
        $user->roles()->attach(Role::where('nome', $perfil->value)->firstOrFail()->id);

        return $user;
    }

    protected function criarDocenteDeAvaliacao(): Docente
    {
        $n = ++self::$alunosDeAvaliacao;
        $pessoa = DadosPessoa::create(['nome_completo' => "Docente {$n}", 'numero_identificacao' => "BI-DOC-AV-{$n}", 'tipo_pessoa' => DadosPessoa::TIPO_PROFESSOR]);

        return Docente::create(['estabelecimento_id' => Estabelecimento::current()->id, 'dados_pessoa_id' => $pessoa->id]);
    }

    /** Professor (com conta) atribuído como titular à turma e disciplina do cenário. */
    protected function professorAtribuido(array $cenario, string $email = 'prof@example.com'): User
    {
        $docente = $this->criarDocenteDeAvaliacao();
        $professor = $this->criarUtilizadorComPerfil(Perfil::PROFESSOR, $email);
        $professor->update(['dados_pessoa_id' => $docente->dados_pessoa_id]);

        AtribuicaoDocente::create([
            'ano_lectivo_id' => $cenario['ano']->id, 'turma_id' => $cenario['turma']->id, 'disciplina_id' => $cenario['disciplina']->id,
            'docente_id' => $docente->id, 'papel' => PapelDocente::TITULAR, 'data_inicio' => '2026-01-01',
        ]);

        return $professor;
    }

    /** Segunda disciplina no plano da turma: devolve o cenário apontado para ela (para `inscreverAluno`/`inscreverNaDisciplina`). */
    protected function outraDisciplina(array $cenario, string $codigo = 'POR'): array
    {
        $disciplina = Disciplina::create(['estabelecimento_id' => Estabelecimento::current()->id, 'codigo' => $codigo, 'nome' => "Disciplina {$codigo}"]);
        $planoDisciplina = PlanoCurricularDisciplina::create(['plano_curricular_id' => $cenario['planoDisciplina']->plano_curricular_id, 'disciplina_id' => $disciplina->id]);

        return [...$cenario, 'disciplina' => $disciplina, 'planoDisciplina' => $planoDisciplina];
    }

    /** Inscreve a matrícula de uma inscrição existente noutra disciplina do cenário. */
    protected function inscreverNaDisciplina(InscricaoDisciplina $existente, array $cenario): InscricaoDisciplina
    {
        return InscricaoDisciplina::create([
            'matricula_id' => $existente->matricula_id, 'plano_curricular_disciplina_id' => $cenario['planoDisciplina']->id,
            'data_inscricao' => '2026-02-01', 'estado' => EstadoInscricaoDisciplinaEnum::INSCRITA,
        ]);
    }

    /**
     * Lança uma contínua e a prova em cada trimestre (instrumentos criados uma vez por disciplina e trimestre).
     *
     * @param  array<int, array{0: string, 1: string}>  $porTrimestre  [trimestre => [mac, pt]]
     */
    protected function notasCompletas(array $cenario, InscricaoDisciplina $inscricao, array $porTrimestre): void
    {
        foreach ($porTrimestre as $n => [$mac, $pt]) {
            foreach ([[TipoInstrumento::MAC, 'A', $mac], [TipoInstrumento::PT, 'PT', $pt]] as [$tipo, $nome, $valor]) {
                $instrumento = AvaliacaoInstrumento::firstOrCreate(
                    ['turma_id' => $cenario['turma']->id, 'disciplina_id' => $cenario['disciplina']->id, 'periodo_id' => $cenario['periodos'][$n]->id, 'tipo' => $tipo],
                    ['nome' => "{$nome}{$n}", 'obrigatorio' => true],
                );
                AvaliacaoNota::create(['instrumento_id' => $instrumento->id, 'inscricao_disciplina_id' => $inscricao->id, 'valor' => $valor, 'estado' => EstadoNota::LANCADA]);
            }
        }
    }

    /**
     * Classe de Pré-Escolar (PRE_I) com a escala INS/SUF/B/MB escolhida para o ano.
     *
     * @return array{0: array, 1: AvaliacaoEscala}
     */
    protected function cenarioQualitativo(string $marca = 'A'): array
    {
        $c = $this->cenarioDeAvaliacao('PRE_I', $marca);
        $c['nivel']->update(['etapa_ensino' => EtapaQualitativa::PRE_ESCOLAR]);
        $escala = $this->guardarEscala(null, "Escala {$marca}", [
            ['codigo' => 'INS', 'designacao' => 'Insuficiente'], ['codigo' => 'SUF', 'designacao' => 'Suficiente'],
            ['codigo' => 'B', 'designacao' => 'Bom'], ['codigo' => 'MB', 'designacao' => 'Muito Bom'],
        ]);
        $this->guardarRegraQualitativa($c['ano']->id, $c['nivel']->id, $escala->id);

        return [$c, $escala];
    }

    // ---- atalhos para as Actions, que recebem DTOs (os testes continuam a ler-se numa linha)

    protected function definirPrazo(int $periodoId, TipoInstrumento $tipo, string $dataLimite): AvaliacaoPrazo
    {
        return app(DefinirPrazoAction::class)->executar(new DefinirPrazoDTO($periodoId, $tipo, $dataLimite));
    }

    protected function prolongarPrazo(int $prazoId, string $dataLimite, string $motivo, ?int $turmaId = null, ?int $disciplinaId = null, ?int $docenteId = null): AvaliacaoPrazoExcepcao
    {
        return app(ProlongarPrazoAction::class)->executar(new ProlongarPrazoDTO($prazoId, $dataLimite, $motivo, $turmaId, $disciplinaId, $docenteId));
    }

    /** @param  list<array{codigo: string, designacao: string, id?: int}>  $niveis */
    protected function guardarEscala(?int $id, string $nome, array $niveis): AvaliacaoEscala
    {
        return app(GuardarEscalaQualitativaAction::class)->executar(new EscalaQualitativaDTO(
            $nome, array_map(fn (array $n) => new NivelDaEscalaDTO($n['codigo'], $n['designacao'], $n['id'] ?? null), $niveis), $id,
        ));
    }

    protected function guardarRegraQualitativa(int $anoLectivoId, int $nivelAcademicoId, int $escalaId, Progressao $progressao = Progressao::AUTOMATICA): AvaliacaoRegraQualitativa
    {
        return app(GuardarRegraQualitativaAction::class)->executar(new RegraQualitativaDTO($anoLectivoId, [$nivelAcademicoId], $escalaId, $progressao));
    }

    /** @param  list<array{descricao: string, ativa?: bool, id?: int}>  $itens */
    protected function guardarCompetencias(int $disciplinaId, ?int $nivelId, array $itens): void
    {
        app(GuardarCompetenciasAction::class)->executar(new CompetenciasDaAreaDTO(
            $disciplinaId, $nivelId, array_map(fn (array $c) => new CompetenciaDTO($c['descricao'], $c['ativa'] ?? true, $c['id'] ?? null), $itens),
        ));
    }

    protected function guardarConfigDocumentos(?string $linhasExtra, ?string $lema, ?string $vistoCargo, ?string $vistoNome): void
    {
        app(GuardarConfigDocumentosAction::class)->executar(new ConfigDocumentosDTO($linhasExtra, $lema, $vistoCargo, $vistoNome));
    }

    protected function lancarExame(int $turmaId, int $disciplinaId, int $inscricaoId, string $valor, ?string $motivo = null): AvaliacaoNota
    {
        return app(LancarExameAction::class)->executar(new LancarExameDTO($turmaId, $disciplinaId, $inscricaoId, $valor, $motivo));
    }

    protected function lancarRecurso(int $turmaId, int $disciplinaId, int $inscricaoId, string $valor, ?string $motivo = null): AvaliacaoNota
    {
        return app(LancarRecursoAction::class)->executar(new LancarExameDTO($turmaId, $disciplinaId, $inscricaoId, $valor, $motivo));
    }

    protected function anularNota(int $notaId, bool $contaComoZero, string $motivo): AvaliacaoNota
    {
        return app(AnularNotaAction::class)->executar(new AnularNotaDTO($notaId, $contaComoZero, $motivo));
    }

    protected function decidirProgressao(int $matriculaId, Decisao $decisao, ?string $motivo = null): AvaliacaoProgressao
    {
        $turmaId = MatriculaDoCenario::query()->findOrFail($matriculaId)->turma_id;

        return app(DecidirProgressaoAction::class)->executar(new DecidirProgressaoDTO($turmaId, $matriculaId, $decisao, $motivo));
    }
}
