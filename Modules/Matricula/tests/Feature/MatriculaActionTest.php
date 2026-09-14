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

    private function criarEstabelecimento(): Estabelecimento
    {
        return Estabelecimento::create([
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => true,
        ]);
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

    public function test_rejeita_aluno_com_enquadramento_academico_diferente_do_curso_da_turma(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $curso = $this->criarCurso($estabelecimento);
        $outroCurso = $this->criarCurso($estabelecimento, 'GES');
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $turma = $this->criarTurma($anoLectivo, $nivel, $curso);
        $aluno = $this->criarAluno($estabelecimento);
        $this->enquadrar($aluno, cursoId: $outroCurso->id);

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
        // Sem enquadramento prévio — a matrícula deve assumir o Curso da turma.

        $matricula = app(CriarMatriculaAction::class)->executar(
            $aluno,
            $this->dto($turma->id, $anoLectivo->id),
        );

        $this->assertNotNull($matricula->id);
        $this->assertDatabaseHas('aluno_enquadramentos_academicos', [
            'aluno_id' => $aluno->id,
            'curso_id' => $curso->id,
            'nivel_academico_id' => null,
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

    public function test_aluno_com_curso_a_em_turma_curso_b_e_rejeitado(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
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
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $cursoA = $this->criarCurso($estabelecimento, 'A');
        $cursoB = $this->criarCurso($estabelecimento, 'B');
        $turmaOriginal = $this->criarTurma($anoLectivo, $nivel, $cursoA);
        $turmaIncompativel = $this->criarTurma($anoLectivo, $nivel, $cursoB);
        $aluno = $this->criarAluno($estabelecimento);
        $this->enquadrar($aluno, cursoId: $cursoA->id);

        $matricula = app(CriarMatriculaAction::class)->executar($aluno, $this->dto($turmaOriginal->id, $anoLectivo->id));

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(AtualizarMatriculaAction::class)->executar(
            $matricula,
            $this->dto($turmaIncompativel->id, $anoLectivo->id, dataMatricula: '2026-02-01'),
        );
    }
}
