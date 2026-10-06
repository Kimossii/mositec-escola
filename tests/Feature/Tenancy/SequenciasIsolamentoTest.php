<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Aluno\Actions\CriarAlunoAction;
use Modules\Aluno\Actions\CriarEnquadramentoAcademicoAlunoAction;
use Modules\Aluno\DTO\AlunoDTO;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Matricula\Actions\CriarMatriculaAction;
use Modules\Matricula\DTO\MatriculaDTO;
use Modules\Matricula\Models\Matricula;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularAnoLectivo;
use Modules\Tenant\Models\Tenant;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\DadosPessoa;
use Tests\TestCase;

/**
 * Fluxo real: duas escolas a matricular no mesmo ano começam ambas em 0001
 * e não colidem com os únicos por tenant.
 */
class SequenciasIsolamentoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $outro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
    }

    /** Cria aluno e matrícula pelas Actions no tenant do contexto. @return array{string, string} */
    private function alunoEMatricula(string $bi): array
    {
        $estabelecimentoId = Estabelecimento::current()->id;

        $ano = AnoLectivo::create([
            'estabelecimento_id' => $estabelecimentoId, 'nome' => (string) now()->year,
            'data_inicio' => now()->year . '-01-01', 'data_fim' => now()->year . '-12-31',
            'estado' => EstadoAnoLectivo::ATIVO,
        ]);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimentoId, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1, 'etapa_ensino' => 1]);
        $turma = Turma::create(['ano_lectivo_id' => $ano->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'T1', 'nome' => 'Turma']);
        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimentoId, 'nivel_academico_id' => $nivel->id, 'codigo' => 'PL1', 'nome' => 'Plano']);
        PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $ano->id]);

        $aluno = app(CriarAlunoAction::class)->executar(new AlunoDTO(
            dadosPessoaId: null, nomeCompleto: 'Aluno ' . $bi, email: null, telefone: null,
            dataNascimento: null, sexo: DadosPessoa::SEXO_FEMININO, numeroIdentificacao: $bi,
        ));
        (new CriarEnquadramentoAcademicoAlunoAction())->executar($aluno, null, $nivel->id);

        $matricula = app(CriarMatriculaAction::class)->executar(
            $aluno,
            new MatriculaDTO(turmaId: $turma->id, anoLectivoId: $ano->id, dataMatricula: null, estado: null, observacoes: null),
        );

        return [$aluno->numero_matricula, $matricula->numero_registo_matricula];
    }

    public function test_duas_escolas_no_mesmo_ano_obtem_ambas_0001_sem_colisao(): void
    {
        $sufixo = '-0001';

        [$matriculaA, $registoA] = $this->alunoEMatricula('BI-A');
        [$matriculaB, $registoB] = $this->noTenant($this->outro, fn () => $this->alunoEMatricula('BI-B'));

        $this->assertStringEndsWith($sufixo, $matriculaA);
        $this->assertStringEndsWith($sufixo, $matriculaB);
        $this->assertStringEndsWith($sufixo, $registoA);
        $this->assertStringEndsWith($sufixo, $registoB);
        $this->assertSame($matriculaA, $matriculaB);
        $this->assertSame($registoA, $registoB);

        $this->assertSame($registoA, Matricula::firstOrFail()->numero_registo_matricula);
        $this->noTenant($this->outro, fn () => $this->assertSame($registoB, Matricula::firstOrFail()->numero_registo_matricula));
    }
}
