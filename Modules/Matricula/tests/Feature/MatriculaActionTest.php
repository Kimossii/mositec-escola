<?php

namespace Modules\Matricula\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Aluno\Actions\CriarEnquadramentoAcademicoAlunoAction;
use Modules\Aluno\Models\Aluno;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Enums\Estado;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Matricula\Actions\AlterarEstadoMatriculaAction;
use Modules\Matricula\Actions\AtualizarMatriculaAction;
use Modules\Matricula\Actions\CriarMatriculaAction;
use Modules\Matricula\Actions\EliminarMatriculaAction;
use Modules\Matricula\Actions\RenovarMatriculaAction;
use Modules\Matricula\Actions\RenovarMatriculasEmMassaAction;
use Modules\Matricula\DTO\MatriculaDTO;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\DadosPessoal;
use Tests\TestCase;

class MatriculaActionTest extends TestCase
{
    use RefreshDatabase;

    private function criarEstabelecimento(?\Modules\Estabelecimento\Enums\TipoEnsinoEnum $tipoEnsino = null): Estabelecimento
    {
        $estabelecimento = Estabelecimento::create([
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => true,
        ]);

        if ($tipoEnsino !== null) {
            $estabelecimento->update(['tipo_ensino' => $tipoEnsino->value]);
        }

        return $estabelecimento;
    }

    private function criarAnoLectivo(Estabelecimento $estabelecimento, string $nome = '2026'): AnoLectivo
    {
        return AnoLectivo::create([
            'estabelecimento_id' => $estabelecimento->id,
            'nome' => $nome,
            'data_inicio' => '2026-01-01',
            'data_fim' => '2026-12-31',
            'estado' => EstadoAnoLectivo::ATIVO,
        ]);
    }

    private function criarCurso(Estabelecimento $estabelecimento, string $codigo = 'INF'): Curso
    {
        return Curso::create([
            'estabelecimento_id' => $estabelecimento->id,
            'codigo' => $codigo,
            'nome' => 'Curso ' . $codigo,
        ]);
    }

    private function criarNivelAcademico(Estabelecimento $estabelecimento, string $codigo = '1C'): NivelAcademico
    {
        return NivelAcademico::create([
            'estabelecimento_id' => $estabelecimento->id,
            'codigo' => $codigo,
            'nome' => 'Nível ' . $codigo,
            'ordem' => 1,
            'etapa_ensino' => 1,
        ]);
    }

    private function criarTurma(AnoLectivo $anoLectivo, NivelAcademico $nivel, ?Curso $curso = null, int $estado = 1): Turma
    {
        return Turma::create([
            'ano_lectivo_id' => $anoLectivo->id,
            'nivel_academico_id' => $nivel->id,
            'curso_id' => $curso?->id,
            'codigo' => 'T' . random_int(1000, 9999),
            'nome' => 'Turma Teste',
            'estado' => $estado,
        ]);
    }

    private function criarAluno(Estabelecimento $estabelecimento, string $numeroIdentificacao = 'BI0001'): Aluno
    {
        $pessoa = DadosPessoal::create([
            'nome_completo' => 'Aluno Teste',
            'numero_identificacao' => $numeroIdentificacao,
            'tipo_pessoa' => DadosPessoal::TIPO_ALUNO,
        ]);

        return Aluno::create([
            'estabelecimento_id' => $estabelecimento->id,
            'dados_pessoa_id' => $pessoa->id,
            'numero_matricula' => '2026-' . random_int(1000, 9999),
        ]);
    }

    private function enquadrar(Aluno $aluno, ?int $cursoId = null, ?int $nivelAcademicoId = null): void
    {
        (new CriarEnquadramentoAcademicoAlunoAction())->executar($aluno, $cursoId, $nivelAcademicoId);
    }

    private function dto(
        int $turmaId,
        int $anoLectivoId,
        ?string $dataMatricula = null,
        ?int $estado = null,
        ?string $observacoes = null,
    ): MatriculaDTO {
        return new MatriculaDTO(
            turmaId: $turmaId,
            anoLectivoId: $anoLectivoId,
            dataMatricula: $dataMatricula,
            estado: $estado,
            observacoes: $observacoes,
        );
    }

    // ---------- Criação ----------

    public function test_cria_matricula_valida_com_curso(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $curso = $this->criarCurso($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $turma = $this->criarTurma($anoLectivo, $nivel, $curso);
        $aluno = $this->criarAluno($estabelecimento);
        $this->enquadrar($aluno, cursoId: $curso->id);

        $matricula = app(CriarMatriculaAction::class)->executar(
            $aluno,
            $this->dto($turma->id, $anoLectivo->id),
        );

        $this->assertNotNull($matricula->id);
        $this->assertSame($aluno->id, $matricula->aluno_id);
        $this->assertSame($turma->id, $matricula->turma_id);
        $this->assertSame($anoLectivo->id, $matricula->ano_lectivo_id);
        $this->assertSame(EstadoMatriculaEnum::PENDENTE, $matricula->estado);
        $this->assertNotNull($matricula->numero_registo_matricula);
    }

    public function test_cria_matricula_valida_sem_curso_usando_nivel_academico(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $turma = $this->criarTurma($anoLectivo, $nivel);
        $aluno = $this->criarAluno($estabelecimento);
        $this->enquadrar($aluno, nivelAcademicoId: $nivel->id);

        $matricula = app(CriarMatriculaAction::class)->executar(
            $aluno,
            $this->dto($turma->id, $anoLectivo->id),
        );

        $this->assertNotNull($matricula->id);
        $this->assertNull($turma->curso_id);
    }

    public function test_permite_aluno_com_enquadramento_noutro_curso_matricular_se_em_segundo_curso(): void
    {
        // Ensino Superior: dupla licenciatura — cursos em paralelo são legítimos.
        $estabelecimento = $this->criarEstabelecimento(\Modules\Estabelecimento\Enums\TipoEnsinoEnum::UNIVERSITARIO);
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $curso = $this->criarCurso($estabelecimento);
        $outroCurso = $this->criarCurso($estabelecimento, 'GES');
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $turma = $this->criarTurma($anoLectivo, $nivel, $curso);
        $aluno = $this->criarAluno($estabelecimento);
        $this->enquadrar($aluno, cursoId: $outroCurso->id);

        $matricula = app(CriarMatriculaAction::class)->executar(
            $aluno,
            $this->dto($turma->id, $anoLectivo->id),
        );

        $this->assertNotNull($matricula->id);
        $this->assertDatabaseHas('aluno_enquadramentos_academicos', [
            'aluno_id' => $aluno->id,
            'curso_id' => $curso->id,
        ]);
        $this->assertDatabaseHas('aluno_enquadramentos_academicos', [
            'aluno_id' => $aluno->id,
            'curso_id' => $outroCurso->id,
        ]);
    }

    public function test_rejeita_aluno_com_enquadramento_noutro_nivel_sem_curso(): void
    {
        // Ensino Geral/Técnico: exclusivo — só um Nível/classe de cada vez.
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento, '5C');
        $outroNivel = $this->criarNivelAcademico($estabelecimento, '6C');
        $turma = $this->criarTurma($anoLectivo, $nivel);
        $aluno = $this->criarAluno($estabelecimento);
        $this->enquadrar($aluno, nivelAcademicoId: $outroNivel->id);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(CriarMatriculaAction::class)->executar(
            $aluno,
            $this->dto($turma->id, $anoLectivo->id),
        );
    }

    public function test_assume_automaticamente_o_enquadramento_academico_quando_aluno_nao_tem_nenhum(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $curso = $this->criarCurso($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $turma = $this->criarTurma($anoLectivo, $nivel, $curso);
        $aluno = $this->criarAluno($estabelecimento);
        // Sem enquadramento prévio — a matrícula deve assumir o Curso e o Nível da turma.

        $matricula = app(CriarMatriculaAction::class)->executar(
            $aluno,
            $this->dto($turma->id, $anoLectivo->id),
        );

        $this->assertNotNull($matricula->id);
        $this->assertDatabaseHas('aluno_enquadramentos_academicos', [
            'aluno_id' => $aluno->id,
            'curso_id' => $curso->id,
            'nivel_academico_id' => $nivel->id,
            'estado' => \Modules\Aluno\Enums\EstadoEnquadramentoAcademicoEnum::ACTIVO->value,
        ]);
    }

    public function test_assume_automaticamente_o_nivel_academico_quando_turma_nao_tem_curso(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $turma = $this->criarTurma($anoLectivo, $nivel);
        $aluno = $this->criarAluno($estabelecimento);

        $matricula = app(CriarMatriculaAction::class)->executar(
            $aluno,
            $this->dto($turma->id, $anoLectivo->id),
        );

        $this->assertNotNull($matricula->id);
        $this->assertDatabaseHas('aluno_enquadramentos_academicos', [
            'aluno_id' => $aluno->id,
            'curso_id' => null,
            'nivel_academico_id' => $nivel->id,
            'estado' => \Modules\Aluno\Enums\EstadoEnquadramentoAcademicoEnum::ACTIVO->value,
        ]);
    }

    public function test_rejeita_turma_inexistente(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $aluno = $this->criarAluno($estabelecimento);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        app(CriarMatriculaAction::class)->executar(
            $aluno,
            $this->dto(999999, $anoLectivo->id),
        );
    }

    public function test_rejeita_turma_inactiva(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $turma = $this->criarTurma($anoLectivo, $nivel, estado: Estado::INATIVO->value);
        $aluno = $this->criarAluno($estabelecimento);
        $this->enquadrar($aluno, nivelAcademicoId: $nivel->id);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(CriarMatriculaAction::class)->executar(
            $aluno,
            $this->dto($turma->id, $anoLectivo->id),
        );
    }

    public function test_rejeita_matricula_em_ano_lectivo_planeado(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivoPlaneado = AnoLectivo::create([
            'estabelecimento_id' => $estabelecimento->id, 'nome' => '2027',
            'data_inicio' => '2027-01-01', 'data_fim' => '2027-12-31', 'estado' => EstadoAnoLectivo::PLANEADO,
        ]);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $turma = $this->criarTurma($anoLectivoPlaneado, $nivel);
        $aluno = $this->criarAluno($estabelecimento);
        $this->enquadrar($aluno, nivelAcademicoId: $nivel->id);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(CriarMatriculaAction::class)->executar(
            $aluno,
            $this->dto($turma->id, $anoLectivoPlaneado->id),
        );
    }

    public function test_rejeita_matricula_em_ano_lectivo_encerrado(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivoEncerrado = AnoLectivo::create([
            'estabelecimento_id' => $estabelecimento->id, 'nome' => '2025',
            'data_inicio' => '2025-01-01', 'data_fim' => '2025-12-31', 'estado' => EstadoAnoLectivo::ENCERRADO,
        ]);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $turma = $this->criarTurma($anoLectivoEncerrado, $nivel);
        $aluno = $this->criarAluno($estabelecimento);
        $this->enquadrar($aluno, nivelAcademicoId: $nivel->id);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(CriarMatriculaAction::class)->executar(
            $aluno,
            $this->dto($turma->id, $anoLectivoEncerrado->id),
        );
    }

    public function test_rejeita_ano_lectivo_incompativel_com_a_turma(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivoTurma = $this->criarAnoLectivo($estabelecimento, '2026');
        $outroAnoLectivo = $this->criarAnoLectivo($estabelecimento, '2027');
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $turma = $this->criarTurma($anoLectivoTurma, $nivel);
        $aluno = $this->criarAluno($estabelecimento);
        $this->enquadrar($aluno, nivelAcademicoId: $nivel->id);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(CriarMatriculaAction::class)->executar(
            $aluno,
            $this->dto($turma->id, $outroAnoLectivo->id),
        );
    }

    public function test_gera_numero_de_registo_sequencial_por_matricula(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $turmaA = $this->criarTurma($anoLectivo, $nivel);
        $turmaB = $this->criarTurma($anoLectivo, $nivel);
        $alunoA = $this->criarAluno($estabelecimento, 'BI0001');
        $alunoB = $this->criarAluno($estabelecimento, 'BI0002');
        $this->enquadrar($alunoA, nivelAcademicoId: $nivel->id);
        $this->enquadrar($alunoB, nivelAcademicoId: $nivel->id);

        $matriculaA = app(CriarMatriculaAction::class)->executar($alunoA, $this->dto($turmaA->id, $anoLectivo->id));
        $matriculaB = app(CriarMatriculaAction::class)->executar($alunoB, $this->dto($turmaB->id, $anoLectivo->id));

        $this->assertNotSame($matriculaA->numero_registo_matricula, $matriculaB->numero_registo_matricula);

        $ano = now()->year;
        $this->assertSame("{$ano}-0001", $matriculaA->numero_registo_matricula);
        $this->assertSame("{$ano}-0002", $matriculaB->numero_registo_matricula);
    }

    /**
     * Não é um teste de concorrência real — os testes correm em SQLite em
     * memória (uma única ligação), onde não é possível ter duas transacções
     * verdadeiramente em paralelo a disputar o `lockForUpdate()`. Isto só
     * garante a integridade da sequência sob chamadas repetidas (sem
     * duplicados, sem saltos); a segurança contra concorrência real depende
     * do `lockForUpdate()` + `upsert()` já implementados, que só um teste
     * multi-processo contra Postgres poderia validar de facto.
     */
    public function test_gerador_numero_registo_produz_sequencia_integra_sob_chamadas_repetidas(): void
    {
        $gerador = app(\Modules\Matricula\Services\GeradorNumeroRegistoMatriculaService::class);

        $numeros = [];
        for ($i = 0; $i < 30; $i++) {
            $numeros[] = $gerador->gerar();
        }

        $this->assertCount(30, array_unique($numeros));

        $ano = now()->year;
        for ($i = 1; $i <= 30; $i++) {
            $this->assertSame(sprintf('%d-%04d', $ano, $i), $numeros[$i - 1]);
        }
    }

    public function test_rejeita_matricula_duplicada_no_mesmo_contexto_academico(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $turmaA = $this->criarTurma($anoLectivo, $nivel);
        $turmaB = $this->criarTurma($anoLectivo, $nivel);
        $aluno = $this->criarAluno($estabelecimento);
        $this->enquadrar($aluno, nivelAcademicoId: $nivel->id);

        app(CriarMatriculaAction::class)->executar($aluno, $this->dto($turmaA->id, $anoLectivo->id));

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(CriarMatriculaAction::class)->executar($aluno, $this->dto($turmaB->id, $anoLectivo->id));
    }

    public function test_rejeita_matricula_duplicada_no_ensino_superior_mesmo_curso_e_mesmo_ano(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $curso = $this->criarCurso($estabelecimento);
        $nivel2Ano = $this->criarNivelAcademico($estabelecimento, '2ANO');
        $turmaA = $this->criarTurma($anoLectivo, $nivel2Ano, $curso);
        $turmaB = $this->criarTurma($anoLectivo, $nivel2Ano, $curso);
        $aluno = $this->criarAluno($estabelecimento);
        $this->enquadrar($aluno, cursoId: $curso->id);

        app(CriarMatriculaAction::class)->executar($aluno, $this->dto($turmaA->id, $anoLectivo->id));

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(CriarMatriculaAction::class)->executar($aluno, $this->dto($turmaB->id, $anoLectivo->id));
    }

    public function test_permite_segunda_matricula_no_ensino_superior_mesmo_curso_ano_diferente(): void
    {
        // Aluno do 2º ano com uma cadeira em atraso do 1º ano — mesmo curso,
        // turmas de anos diferentes. A Matrícula não gere disciplinas, só o
        // vínculo à Turma, por isso ambas devem ser permitidas.
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $curso = $this->criarCurso($estabelecimento);
        $nivel1Ano = $this->criarNivelAcademico($estabelecimento, '1ANO');
        $nivel2Ano = $this->criarNivelAcademico($estabelecimento, '2ANO');
        $turma1Ano = $this->criarTurma($anoLectivo, $nivel1Ano, $curso);
        $turma2Ano = $this->criarTurma($anoLectivo, $nivel2Ano, $curso);
        $aluno = $this->criarAluno($estabelecimento);
        $this->enquadrar($aluno, cursoId: $curso->id);

        $matricula2Ano = app(CriarMatriculaAction::class)->executar($aluno, $this->dto($turma2Ano->id, $anoLectivo->id));
        $matricula1Ano = app(CriarMatriculaAction::class)->executar($aluno, $this->dto($turma1Ano->id, $anoLectivo->id));

        $this->assertNotNull($matricula2Ano->id);
        $this->assertNotNull($matricula1Ano->id);
        $this->assertNotSame($matricula2Ano->id, $matricula1Ano->id);
    }

    // ---------- Regras académicas ----------

    public function test_aluno_com_curso_a_em_turma_curso_a_e_permitido(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $cursoA = $this->criarCurso($estabelecimento, 'A');
        $turma = $this->criarTurma($anoLectivo, $nivel, $cursoA);
        $aluno = $this->criarAluno($estabelecimento);
        $this->enquadrar($aluno, cursoId: $cursoA->id);

        $matricula = app(CriarMatriculaAction::class)->executar($aluno, $this->dto($turma->id, $anoLectivo->id));

        $this->assertNotNull($matricula->id);
    }

    public function test_aluno_com_curso_a_em_turma_curso_b_e_permitido_como_segunda_licenciatura(): void
    {
        $estabelecimento = $this->criarEstabelecimento(\Modules\Estabelecimento\Enums\TipoEnsinoEnum::UNIVERSITARIO);
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $cursoA = $this->criarCurso($estabelecimento, 'A');
        $cursoB = $this->criarCurso($estabelecimento, 'B');
        $turma = $this->criarTurma($anoLectivo, $nivel, $cursoB);
        $aluno = $this->criarAluno($estabelecimento);
        $this->enquadrar($aluno, cursoId: $cursoA->id);

        $matricula = app(CriarMatriculaAction::class)->executar($aluno, $this->dto($turma->id, $anoLectivo->id));

        $this->assertNotNull($matricula->id);
    }

    public function test_aluno_com_curso_a_em_turma_curso_b_e_rejeitado_no_ensino_tecnico(): void
    {
        // Fora do Universitário (ex.: Técnico), um Curso continua exclusivo.
        $estabelecimento = $this->criarEstabelecimento(\Modules\Estabelecimento\Enums\TipoEnsinoEnum::TECNICO);
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $cursoA = $this->criarCurso($estabelecimento, 'A');
        $cursoB = $this->criarCurso($estabelecimento, 'B');
        $turma = $this->criarTurma($anoLectivo, $nivel, $cursoB);
        $aluno = $this->criarAluno($estabelecimento);
        $this->enquadrar($aluno, cursoId: $cursoA->id);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(CriarMatriculaAction::class)->executar($aluno, $this->dto($turma->id, $anoLectivo->id));
    }

    public function test_aluno_com_nivel_5a_em_turma_nivel_5a_e_permitido(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel5a = $this->criarNivelAcademico($estabelecimento, '5C');
        $turma = $this->criarTurma($anoLectivo, $nivel5a);
        $aluno = $this->criarAluno($estabelecimento);
        $this->enquadrar($aluno, nivelAcademicoId: $nivel5a->id);

        $matricula = app(CriarMatriculaAction::class)->executar($aluno, $this->dto($turma->id, $anoLectivo->id));

        $this->assertNotNull($matricula->id);
    }

    public function test_aluno_com_nivel_5a_em_turma_nivel_6a_e_rejeitado(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel5a = $this->criarNivelAcademico($estabelecimento, '5C');
        $nivel6a = $this->criarNivelAcademico($estabelecimento, '6C');
        $turma = $this->criarTurma($anoLectivo, $nivel6a);
        $aluno = $this->criarAluno($estabelecimento);
        $this->enquadrar($aluno, nivelAcademicoId: $nivel5a->id);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(CriarMatriculaAction::class)->executar($aluno, $this->dto($turma->id, $anoLectivo->id));
    }

    // ---------- Estado ----------

    private function criarMatriculaPendente(): Matricula
    {
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $turma = $this->criarTurma($anoLectivo, $nivel);
        $aluno = $this->criarAluno($estabelecimento);
        $this->enquadrar($aluno, nivelAcademicoId: $nivel->id);

        return app(CriarMatriculaAction::class)->executar($aluno, $this->dto($turma->id, $anoLectivo->id));
    }

    public function test_transicao_pendente_para_activa(): void
    {
        $matricula = $this->criarMatriculaPendente();

        $actualizada = app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::ACTIVA);

        $this->assertSame(EstadoMatriculaEnum::ACTIVA, $actualizada->estado);
    }

    public function test_transicao_pendente_para_cancelada(): void
    {
        $matricula = $this->criarMatriculaPendente();

        $actualizada = app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::CANCELADA);

        $this->assertSame(EstadoMatriculaEnum::CANCELADA, $actualizada->estado);
    }

    public function test_transicao_activa_para_concluida(): void
    {
        $matricula = $this->criarMatriculaPendente();
        $matricula = app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::ACTIVA);

        $actualizada = app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::CONCLUIDA);

        $this->assertSame(EstadoMatriculaEnum::CONCLUIDA, $actualizada->estado);
    }

    public function test_transicao_activa_para_cancelada(): void
    {
        $matricula = $this->criarMatriculaPendente();
        $matricula = app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::ACTIVA);

        $actualizada = app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::CANCELADA);

        $this->assertSame(EstadoMatriculaEnum::CANCELADA, $actualizada->estado);
    }

    public function test_transicao_activa_para_transferida(): void
    {
        $matricula = $this->criarMatriculaPendente();
        $matricula = app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::ACTIVA);

        $actualizada = app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::TRANSFERIDA);

        $this->assertSame(EstadoMatriculaEnum::TRANSFERIDA, $actualizada->estado);
    }

    public function test_transicao_invalida_pendente_para_concluida_lanca_excecao(): void
    {
        $matricula = $this->criarMatriculaPendente();

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::CONCLUIDA);
    }

    public function test_estados_terminais_nao_permitem_nova_transicao(): void
    {
        $matricula = $this->criarMatriculaPendente();
        $matricula = app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::CANCELADA);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::ACTIVA);
    }

    // ---------- Actualização ----------

    public function test_altera_turma_da_matricula_validamente(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $turmaOriginal = $this->criarTurma($anoLectivo, $nivel);
        $turmaNova = $this->criarTurma($anoLectivo, $nivel);
        $aluno = $this->criarAluno($estabelecimento);
        $this->enquadrar($aluno, nivelAcademicoId: $nivel->id);

        $matricula = app(CriarMatriculaAction::class)->executar($aluno, $this->dto($turmaOriginal->id, $anoLectivo->id));
        $numeroOriginal = $matricula->numero_registo_matricula;

        $actualizada = app(AtualizarMatriculaAction::class)->executar(
            $matricula,
            $this->dto($turmaNova->id, $anoLectivo->id, dataMatricula: '2026-02-01'),
        );

        $this->assertSame($turmaNova->id, $actualizada->turma_id);
        $this->assertSame($numeroOriginal, $actualizada->numero_registo_matricula);
    }

    public function test_rejeita_turma_incompativel_com_o_enquadramento_na_actualizacao(): void
    {
        // Ensino Geral/Técnico (sem curso): o Nível continua exclusivo.
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento, '5C');
        $outroNivel = $this->criarNivelAcademico($estabelecimento, '6C');
        $turmaOriginal = $this->criarTurma($anoLectivo, $nivel);
        $turmaIncompativel = $this->criarTurma($anoLectivo, $outroNivel);
        $aluno = $this->criarAluno($estabelecimento);
        $this->enquadrar($aluno, nivelAcademicoId: $nivel->id);

        $matricula = app(CriarMatriculaAction::class)->executar($aluno, $this->dto($turmaOriginal->id, $anoLectivo->id));

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(AtualizarMatriculaAction::class)->executar(
            $matricula,
            $this->dto($turmaIncompativel->id, $anoLectivo->id, dataMatricula: '2026-02-01'),
        );
    }

    public function test_permite_actualizar_matricula_para_turma_de_outro_curso_no_ensino_superior(): void
    {
        $estabelecimento = $this->criarEstabelecimento(\Modules\Estabelecimento\Enums\TipoEnsinoEnum::UNIVERSITARIO);
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $cursoA = $this->criarCurso($estabelecimento, 'A');
        $cursoB = $this->criarCurso($estabelecimento, 'B');
        $turmaOriginal = $this->criarTurma($anoLectivo, $nivel, $cursoA);
        $turmaOutroCurso = $this->criarTurma($anoLectivo, $nivel, $cursoB);
        $aluno = $this->criarAluno($estabelecimento);
        $this->enquadrar($aluno, cursoId: $cursoA->id);

        $matricula = app(CriarMatriculaAction::class)->executar($aluno, $this->dto($turmaOriginal->id, $anoLectivo->id));

        $actualizada = app(AtualizarMatriculaAction::class)->executar(
            $matricula,
            $this->dto($turmaOutroCurso->id, $anoLectivo->id, dataMatricula: '2026-02-01'),
        );

        $this->assertSame($turmaOutroCurso->id, $actualizada->turma_id);
    }

    // ---------- data_fim ----------

    public function test_preenche_data_fim_ao_cancelar_matricula_activa(): void
    {
        $matricula = $this->criarMatriculaPendente();
        $matricula = app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::ACTIVA);

        $actualizada = app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::CANCELADA);

        $this->assertNotNull($actualizada->data_fim);
        $this->assertSame(now()->toDateString(), $actualizada->data_fim->toDateString());
    }

    public function test_permite_indicar_data_fim_explicita_ao_alterar_estado(): void
    {
        $matricula = $this->criarMatriculaPendente();
        $matricula = app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::ACTIVA);

        $actualizada = app(AlterarEstadoMatriculaAction::class)->executar(
            $matricula,
            EstadoMatriculaEnum::CANCELADA,
            dataFim: '2026-03-15',
        );

        $this->assertSame('2026-03-15', $actualizada->data_fim->toDateString());
    }

    public function test_nao_preenche_data_fim_ao_activar_matricula_pendente(): void
    {
        $matricula = $this->criarMatriculaPendente();

        $actualizada = app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::ACTIVA);

        $this->assertNull($actualizada->data_fim);
    }

    // ---------- Renovação ----------

    public function test_renova_matricula_concluida_sugerindo_turma_seguinte_automaticamente(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivoAtual = AnoLectivo::create([
            'estabelecimento_id' => $estabelecimento->id,
            'nome' => '2026',
            'data_inicio' => '2026-01-01',
            'data_fim' => '2026-12-31',
            'estado' => EstadoAnoLectivo::ATIVO,
        ]);
        $anoLectivoSeguinte = AnoLectivo::create([
            'estabelecimento_id' => $estabelecimento->id,
            'nome' => '2027',
            'data_inicio' => '2027-01-01',
            'data_fim' => '2027-12-31',
            'estado' => EstadoAnoLectivo::ATIVO,
        ]);
        $nivel5 = NivelAcademico::create([
            'estabelecimento_id' => $estabelecimento->id, 'codigo' => '5C', 'nome' => '5ª Classe',
            'ordem' => 5, 'etapa_ensino' => 3,
        ]);
        $nivel6 = NivelAcademico::create([
            'estabelecimento_id' => $estabelecimento->id, 'codigo' => '6C', 'nome' => '6ª Classe',
            'ordem' => 6, 'etapa_ensino' => 3,
        ]);
        $turmaAtual = $this->criarTurma($anoLectivoAtual, $nivel5);
        $turmaSeguinte = $this->criarTurma($anoLectivoSeguinte, $nivel6);
        $aluno = $this->criarAluno($estabelecimento);
        $this->enquadrar($aluno, nivelAcademicoId: $nivel5->id);

        $matriculaAtual = app(CriarMatriculaAction::class)->executar($aluno, $this->dto($turmaAtual->id, $anoLectivoAtual->id));
        app(AlterarEstadoMatriculaAction::class)->executar($matriculaAtual, EstadoMatriculaEnum::ACTIVA);
        $matriculaAtual = app(AlterarEstadoMatriculaAction::class)->executar($matriculaAtual, EstadoMatriculaEnum::CONCLUIDA);

        $novaMatricula = app(RenovarMatriculaAction::class)->executar($matriculaAtual);

        $this->assertSame($turmaSeguinte->id, $novaMatricula->turma_id);
        $this->assertSame($anoLectivoSeguinte->id, $novaMatricula->ano_lectivo_id);
        $this->assertSame(EstadoMatriculaEnum::PENDENTE, $novaMatricula->estado);
    }

    public function test_renovar_matricula_activa_conclui_automaticamente_antes_de_renovar(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivoAtual = AnoLectivo::create([
            'estabelecimento_id' => $estabelecimento->id, 'nome' => '2026',
            'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31', 'estado' => EstadoAnoLectivo::ATIVO,
        ]);
        $anoLectivoSeguinte = AnoLectivo::create([
            'estabelecimento_id' => $estabelecimento->id, 'nome' => '2027',
            'data_inicio' => '2027-01-01', 'data_fim' => '2027-12-31', 'estado' => EstadoAnoLectivo::ATIVO,
        ]);
        $nivel5 = NivelAcademico::create([
            'estabelecimento_id' => $estabelecimento->id, 'codigo' => '5C', 'nome' => '5ª Classe',
            'ordem' => 5, 'etapa_ensino' => 3,
        ]);
        $nivel6 = NivelAcademico::create([
            'estabelecimento_id' => $estabelecimento->id, 'codigo' => '6C', 'nome' => '6ª Classe',
            'ordem' => 6, 'etapa_ensino' => 3,
        ]);
        $turmaAtual = $this->criarTurma($anoLectivoAtual, $nivel5);
        $this->criarTurma($anoLectivoSeguinte, $nivel6);
        $aluno = $this->criarAluno($estabelecimento);
        $this->enquadrar($aluno, nivelAcademicoId: $nivel5->id);

        $matriculaAtual = app(CriarMatriculaAction::class)->executar($aluno, $this->dto($turmaAtual->id, $anoLectivoAtual->id));
        $matriculaAtual = app(AlterarEstadoMatriculaAction::class)->executar($matriculaAtual, EstadoMatriculaEnum::ACTIVA);

        app(RenovarMatriculaAction::class)->executar($matriculaAtual);

        $this->assertSame(EstadoMatriculaEnum::CONCLUIDA, $matriculaAtual->fresh()->estado);
    }

    public function test_renovar_com_turma_id_explicito_ignora_sugestao_automatica(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $turmaAtual = $this->criarTurma($anoLectivo, $nivel);
        $turmaEscolhida = $this->criarTurma($anoLectivo, $nivel);
        $aluno = $this->criarAluno($estabelecimento);
        $this->enquadrar($aluno, nivelAcademicoId: $nivel->id);

        $matricula = app(CriarMatriculaAction::class)->executar($aluno, $this->dto($turmaAtual->id, $anoLectivo->id));
        $matricula = app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::ACTIVA);
        $matricula = app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::CONCLUIDA);

        $novaMatricula = app(RenovarMatriculaAction::class)->executar($matricula, turmaId: $turmaEscolhida->id);

        $this->assertSame($turmaEscolhida->id, $novaMatricula->turma_id);
    }

    public function test_renovar_sem_turma_seguinte_disponivel_pede_indicacao_manual(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $turmaAtual = $this->criarTurma($anoLectivo, $nivel);
        $aluno = $this->criarAluno($estabelecimento);
        $this->enquadrar($aluno, nivelAcademicoId: $nivel->id);

        $matricula = app(CriarMatriculaAction::class)->executar($aluno, $this->dto($turmaAtual->id, $anoLectivo->id));
        $matricula = app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::ACTIVA);
        $matricula = app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::CONCLUIDA);
        // Sem ano lectivo seguinte nem nível seguinte criados.

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(RenovarMatriculaAction::class)->executar($matricula);
    }

    public function test_rejeita_renovar_matricula_pendente(): void
    {
        $matricula = $this->criarMatriculaPendente();

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(RenovarMatriculaAction::class)->executar($matricula);
    }

    // ---------- Editar em estado terminal ----------

    public function test_rejeita_editar_matricula_concluida(): void
    {
        $matricula = $this->criarMatriculaPendente();
        $matricula = app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::ACTIVA);
        $matricula = app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::CONCLUIDA);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(AtualizarMatriculaAction::class)->executar(
            $matricula,
            $this->dto($matricula->turma_id, $matricula->ano_lectivo_id, dataMatricula: '2026-03-01'),
        );
    }

    // ---------- Eliminar ----------

    public function test_elimina_matricula_pendente(): void
    {
        $matricula = $this->criarMatriculaPendente();

        app(EliminarMatriculaAction::class)->executar($matricula);

        $this->assertSoftDeleted('matriculas', ['id' => $matricula->id]);
    }

    public function test_rejeita_eliminar_matricula_activa(): void
    {
        $matricula = $this->criarMatriculaPendente();
        $matricula = app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::ACTIVA);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(EliminarMatriculaAction::class)->executar($matricula);
    }

    // ---------- Histórico ----------

    public function test_regista_historico_na_criacao_e_em_cada_alteracao_de_estado(): void
    {
        $matricula = $this->criarMatriculaPendente();

        $this->assertDatabaseHas('matricula_historicos', [
            'matricula_id' => $matricula->id,
            'estado_anterior' => null,
            'estado_novo' => EstadoMatriculaEnum::PENDENTE->value,
        ]);

        $matricula = app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::ACTIVA);

        $this->assertDatabaseHas('matricula_historicos', [
            'matricula_id' => $matricula->id,
            'estado_anterior' => EstadoMatriculaEnum::PENDENTE->value,
            'estado_novo' => EstadoMatriculaEnum::ACTIVA->value,
        ]);

        app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::CANCELADA);

        $this->assertDatabaseHas('matricula_historicos', [
            'matricula_id' => $matricula->id,
            'estado_anterior' => EstadoMatriculaEnum::ACTIVA->value,
            'estado_novo' => EstadoMatriculaEnum::CANCELADA->value,
        ]);

        $historico = app(\Modules\Matricula\Services\MatriculaConsultaService::class)->historicoDaMatricula($matricula->fresh());
        $this->assertCount(3, $historico);
    }

    // ---------- Renovação em massa ----------

    public function test_renova_em_massa_processa_cada_matricula_independentemente(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivoAtual = AnoLectivo::create([
            'estabelecimento_id' => $estabelecimento->id, 'nome' => '2026',
            'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31', 'estado' => EstadoAnoLectivo::ATIVO,
        ]);
        $anoLectivoSeguinte = AnoLectivo::create([
            'estabelecimento_id' => $estabelecimento->id, 'nome' => '2027',
            'data_inicio' => '2027-01-01', 'data_fim' => '2027-12-31', 'estado' => EstadoAnoLectivo::ATIVO,
        ]);
        $nivel5 = NivelAcademico::create([
            'estabelecimento_id' => $estabelecimento->id, 'codigo' => '5C', 'nome' => '5ª Classe', 'ordem' => 5, 'etapa_ensino' => 3,
        ]);
        $nivel6 = NivelAcademico::create([
            'estabelecimento_id' => $estabelecimento->id, 'codigo' => '6C', 'nome' => '6ª Classe', 'ordem' => 6, 'etapa_ensino' => 3,
        ]);
        $turmaAtual = $this->criarTurma($anoLectivoAtual, $nivel5);
        $this->criarTurma($anoLectivoSeguinte, $nivel6); // turma seguinte só para o aluno A

        // Aluno A — pode renovar (turma seguinte existe para o seu nível).
        $alunoA = $this->criarAluno($estabelecimento, 'BI0001');
        $this->enquadrar($alunoA, nivelAcademicoId: $nivel5->id);
        $matriculaA = app(CriarMatriculaAction::class)->executar($alunoA, $this->dto($turmaAtual->id, $anoLectivoAtual->id));
        app(AlterarEstadoMatriculaAction::class)->executar($matriculaA, EstadoMatriculaEnum::ACTIVA);
        $matriculaA = app(AlterarEstadoMatriculaAction::class)->executar($matriculaA, EstadoMatriculaEnum::CONCLUIDA);

        // Aluno B — ainda Pendente, RenovarMatriculaAction rejeita este estado.
        $alunoB = $this->criarAluno($estabelecimento, 'BI0002');
        $this->enquadrar($alunoB, nivelAcademicoId: $nivel5->id);
        $matriculaBOutraTurma = $this->criarTurma($anoLectivoAtual, $nivel5);
        $matriculaB = app(CriarMatriculaAction::class)->executar($alunoB, $this->dto($matriculaBOutraTurma->id, $anoLectivoAtual->id));

        $resultado = app(RenovarMatriculasEmMassaAction::class)->executar([$matriculaA->id, $matriculaB->id]);

        $this->assertSame(1, $resultado['sucesso']);
        $this->assertCount(1, $resultado['falhas']);
        $this->assertArrayHasKey($matriculaB->id, $resultado['falhas']);
        $this->assertDatabaseHas('matriculas', [
            'aluno_id' => $alunoA->id,
            'ano_lectivo_id' => $anoLectivoSeguinte->id,
        ]);
    }

    public function test_renova_em_massa_regista_falha_para_matricula_inexistente(): void
    {
        $resultado = app(RenovarMatriculasEmMassaAction::class)->executar([999999]);

        $this->assertSame(0, $resultado['sucesso']);
        $this->assertArrayHasKey(999999, $resultado['falhas']);
    }
}
