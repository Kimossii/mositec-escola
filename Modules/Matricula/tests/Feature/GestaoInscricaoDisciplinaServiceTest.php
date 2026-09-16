<?php

namespace Modules\Matricula\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Aluno\Actions\CriarEnquadramentoAcademicoAlunoAction;
use Modules\Aluno\Models\Aluno;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Http\Requests\AlterarEstadoInscricaoDisciplinaRequest;
use Modules\Matricula\Http\Requests\CriarInscricaoDisciplinaRequest;
use Modules\Matricula\Models\Matricula;
use Modules\Matricula\Services\GestaoInscricaoDisciplinaService;
use Modules\Disciplina\Models\Disciplina;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\DadosPessoal;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class GestaoInscricaoDisciplinaServiceTest extends TestCase
{
    use RefreshDatabase;

    // Constrói a Matrícula directamente no modelo (ver Task 2) — este teste
    // verifica GestaoInscricaoDisciplinaService em isolamento.
    public function test_criar_e_alterar_estado_via_service(): void
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($staff);

        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        $anoLectivo = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026', 'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1, 'etapa_ensino' => 1]);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);
        $pessoa = DadosPessoal::create(['nome_completo' => 'Aluno Teste', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);
        (new CriarEnquadramentoAcademicoAlunoAction())->executar($aluno, nivelAcademicoId: $nivel->id);

        $matricula = Matricula::create([
            'aluno_id' => $aluno->id,
            'turma_id' => $turma->id,
            'ano_lectivo_id' => $anoLectivo->id,
            'numero_registo_matricula' => '2026-0001',
            'data_matricula' => '2026-02-01',
            'estado' => EstadoMatriculaEnum::PENDENTE->value,
        ]);

        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'PL1', 'nome' => 'Plano 1']);
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'MAT1', 'nome' => 'Matemática I']);
        $planoDisciplina = PlanoCurricularDisciplina::create(['plano_curricular_id' => $plano->id, 'disciplina_id' => $disciplina->id]);

        $criarRequest = CriarInscricaoDisciplinaRequest::create('/x', 'POST', ['plano_curricular_disciplina_id' => $planoDisciplina->id]);
        $criarRequest->setContainer($this->app);
        $criarRequest->setUserResolver(fn () => $staff);
        $criarRequest->validateResolved();
        $service = app(GestaoInscricaoDisciplinaService::class);
        $inscricao = $service->criar($matricula, $criarRequest);

        $this->assertSame(EstadoInscricaoDisciplinaEnum::INSCRITA, $inscricao->estado);

        $alterarRequest = AlterarEstadoInscricaoDisciplinaRequest::create('/x', 'PATCH', ['estado' => EstadoInscricaoDisciplinaEnum::CONCLUIDA->value]);
        $alterarRequest->setContainer($this->app);
        $alterarRequest->setUserResolver(fn () => $staff);
        $alterarRequest->validateResolved();
        $concluida = $service->alterarEstado($inscricao, EstadoInscricaoDisciplinaEnum::from((int) $alterarRequest->validated('estado')));

        $this->assertSame(EstadoInscricaoDisciplinaEnum::CONCLUIDA, $concluida->estado);
    }
}
