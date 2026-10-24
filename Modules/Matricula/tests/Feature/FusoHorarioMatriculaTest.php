<?php

namespace Modules\Matricula\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Aluno\Actions\CriarEnquadramentoAcademicoAlunoAction;
use Modules\Aluno\Models\Aluno;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Matricula\Actions\AlterarEstadoInscricaoDisciplinaAction;
use Modules\Matricula\Actions\AlterarEstadoMatriculaAction;
use Modules\Matricula\Actions\CriarInscricaoDisciplinaAction;
use Modules\Matricula\Actions\CriarMatriculaAction;
use Modules\Matricula\Actions\RenovarMatriculaAction;
use Modules\Matricula\DTO\MatriculaDTO;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularAnoLectivo;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\DadosPessoa;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * As datas civis da Matrícula (que alimentam as Propinas) são as do fuso da escola, não as de UTC:
 * às 23:30 UTC do último dia do mês já é o dia 1 do mês seguinte em Luanda (UTC+1).
 */
class FusoHorarioMatriculaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array<string, array{string, string}> fuso da escola => data civil esperada */
    public static function fusos(): array
    {
        return [
            'Luanda já está no dia seguinte' => ['Africa/Luanda', '2026-11-01'],
            'UTC ainda está no último dia do mês' => ['UTC', '2026-10-31'],
        ];
    }

    private function congelar(string $fuso): void
    {
        Estabelecimento::current()->update(['fuso_horario' => $fuso]);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-31 23:30:00', 'UTC'));
    }

    private function escola(): array
    {
        $estabelecimento = $this->estabelecimentoDeTeste(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value]);
        $ano = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026', 'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1, 'etapa_ensino' => 1]);
        $turma = Turma::create(['ano_lectivo_id' => $ano->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);
        $pessoa = DadosPessoa::create(['nome_completo' => 'Aluno Teste', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);
        (new CriarEnquadramentoAcademicoAlunoAction())->executar($aluno, nivelAcademicoId: $nivel->id);

        return [$estabelecimento, $ano, $nivel, $turma, $aluno];
    }

    private function planoConfirmado(Estabelecimento $estabelecimento, AnoLectivo $ano, NivelAcademico $nivel, string $codigo): PlanoCurricular
    {
        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'nivel_academico_id' => $nivel->id, 'codigo' => $codigo, 'nome' => 'Plano ' . $codigo]);
        PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $ano->id]);

        return $plano;
    }

    private function matriculaDirecta(Aluno $aluno, Turma $turma, AnoLectivo $ano, EstadoMatriculaEnum $estado): Matricula
    {
        return Matricula::create([
            'aluno_id' => $aluno->id,
            'turma_id' => $turma->id,
            'ano_lectivo_id' => $ano->id,
            'numero_registo_matricula' => 'DIRECTA-0001',
            'data_matricula' => '2026-02-01',
            'estado' => $estado->value,
        ]);
    }

    #[DataProvider('fusos')]
    public function test_data_matricula_e_o_dia_civil_da_escola(string $fuso, string $esperada): void
    {
        [$estabelecimento, $ano, $nivel, $turma, $aluno] = $this->escola();
        $this->planoConfirmado($estabelecimento, $ano, $nivel, 'PL1');
        $this->congelar($fuso);

        $matricula = app(CriarMatriculaAction::class)->executar($aluno, new MatriculaDTO(turmaId: $turma->id, anoLectivoId: $ano->id, dataMatricula: null, estado: null, observacoes: null));

        $this->assertSame($esperada, $matricula->fresh()->data_matricula->toDateString());
    }

    #[DataProvider('fusos')]
    public function test_data_fim_da_matricula_e_o_dia_civil_da_escola(string $fuso, string $esperada): void
    {
        [, $ano, , $turma, $aluno] = $this->escola();
        $matricula = $this->matriculaDirecta($aluno, $turma, $ano, EstadoMatriculaEnum::ACTIVA);
        $this->congelar($fuso);

        $actualizada = app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::CONCLUIDA);

        $this->assertSame($esperada, $actualizada->fresh()->data_fim->toDateString());
    }

    #[DataProvider('fusos')]
    public function test_data_fim_do_enquadramento_na_renovacao_e_o_dia_civil_da_escola(string $fuso, string $esperada): void
    {
        [$estabelecimento, $ano, $nivel1, $turma, $aluno] = $this->escola();
        $ano2 = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2027', 'data_inicio' => '2027-01-01', 'data_fim' => '2027-12-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $nivel2 = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => '2C', 'nome' => '2ª Classe', 'ordem' => 2, 'etapa_ensino' => 1]);
        Turma::create(['ano_lectivo_id' => $ano2->id, 'nivel_academico_id' => $nivel2->id, 'codigo' => 'T2', 'nome' => 'Turma 2']);
        $this->planoConfirmado($estabelecimento, $ano2, $nivel2, 'PL2');
        $matricula = $this->matriculaDirecta($aluno, $turma, $ano, EstadoMatriculaEnum::CONCLUIDA);
        $this->congelar($fuso);

        app(RenovarMatriculaAction::class)->executar($matricula);

        $enquadramento = $aluno->enquadramentosAcademicos()->where('nivel_academico_id', $nivel1->id)->firstOrFail();
        $this->assertSame($esperada, $enquadramento->data_fim->toDateString());
    }

    /** @return array{Matricula, PlanoCurricularDisciplina} */
    private function cenarioInscricao(): array
    {
        [$estabelecimento, $ano, $nivel, $turma, $aluno] = $this->escola();
        $matricula = $this->matriculaDirecta($aluno, $turma, $ano, EstadoMatriculaEnum::PENDENTE);
        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'PL1', 'nome' => 'Plano 1']);
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'MAT1', 'nome' => 'Matemática I']);

        return [$matricula, PlanoCurricularDisciplina::create(['plano_curricular_id' => $plano->id, 'disciplina_id' => $disciplina->id])];
    }

    #[DataProvider('fusos')]
    public function test_data_inscricao_e_o_dia_civil_da_escola(string $fuso, string $esperada): void
    {
        [$matricula, $planoDisciplina] = $this->cenarioInscricao();
        $this->congelar($fuso);

        $inscricao = app(CriarInscricaoDisciplinaAction::class)->executar($matricula, $planoDisciplina);

        $this->assertSame($esperada, $inscricao->fresh()->data_inscricao->toDateString());
    }

    #[DataProvider('fusos')]
    public function test_data_conclusao_da_inscricao_e_o_dia_civil_da_escola(string $fuso, string $esperada): void
    {
        [$matricula, $planoDisciplina] = $this->cenarioInscricao();
        $inscricao = app(CriarInscricaoDisciplinaAction::class)->executar($matricula, $planoDisciplina);
        $this->congelar($fuso);

        $concluida = app(AlterarEstadoInscricaoDisciplinaAction::class)->executar($inscricao, EstadoInscricaoDisciplinaEnum::CONCLUIDA);

        $this->assertSame($esperada, $concluida->fresh()->data_conclusao->toDateString());
    }
}
