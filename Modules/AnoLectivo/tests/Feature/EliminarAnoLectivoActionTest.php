<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Actions\EliminarAnoLectivoAction;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularAnoLectivo;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\DadosPessoal;
use Modules\Usuario\Models\User;
use Modules\Aluno\Models\Aluno;
use Tests\TestCase;

class EliminarAnoLectivoActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($staff);
    }

    public function test_elimina_ano_lectivo_sem_dependentes_como_soft_delete(): void
    {
        $anoLectivo = AnoLectivo::create([
            'nome' => '2024/2025',
            'data_inicio' => '2024-09-01',
            'data_fim' => '2025-07-31',
            'estado' => EstadoAnoLectivo::ENCERRADO->value,
        ]);

        (new EliminarAnoLectivoAction())->executar($anoLectivo);

        $this->assertSoftDeleted('ano_lectivos', ['id' => $anoLectivo->id]);
    }

    public function test_bloqueia_eliminacao_quando_existem_periodos(): void
    {
        $anoLectivo = AnoLectivo::create([
            'nome' => '2024/2025',
            'data_inicio' => '2024-09-01',
            'data_fim' => '2025-07-31',
            'estado' => EstadoAnoLectivo::ENCERRADO->value,
        ]);

        $anoLectivo->periodos()->create([
            'nome' => '1.º Trimestre',
            'tipo' => TipoPeriodo::TRIMESTRE->value,
            'numero' => 1,
            'data_inicio' => '2024-09-01',
            'data_fim' => '2024-12-15',
        ]);

        $this->expectException(ValidationException::class);

        try {
            (new EliminarAnoLectivoAction())->executar($anoLectivo);
        } finally {
            $this->assertDatabaseHas('ano_lectivos', ['id' => $anoLectivo->id, 'deleted_at' => null]);
        }
    }

    public function test_bloqueia_eliminacao_quando_existem_turmas(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        $anoLectivo = AnoLectivo::create([
            'estabelecimento_id' => $estabelecimento->id, 'nome' => '2024/2025',
            'data_inicio' => '2024-09-01', 'data_fim' => '2025-07-31', 'estado' => EstadoAnoLectivo::ENCERRADO->value,
        ]);
        $nivel = NivelAcademico::create([
            'estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1, 'etapa_ensino' => 1,
        ]);
        Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);

        $this->expectException(ValidationException::class);

        try {
            (new EliminarAnoLectivoAction())->executar($anoLectivo);
        } finally {
            $this->assertDatabaseHas('ano_lectivos', ['id' => $anoLectivo->id, 'deleted_at' => null]);
        }
    }

    public function test_bloqueia_eliminacao_quando_existem_matriculas(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        $anoLectivo = AnoLectivo::create([
            'estabelecimento_id' => $estabelecimento->id, 'nome' => '2024/2025',
            'data_inicio' => '2024-09-01', 'data_fim' => '2025-07-31', 'estado' => EstadoAnoLectivo::ENCERRADO->value,
        ]);
        $nivel = NivelAcademico::create([
            'estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1, 'etapa_ensino' => 1,
        ]);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);
        $pessoa = DadosPessoal::create(['nome_completo' => 'Aluno Teste', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);
        Matricula::create([
            'aluno_id' => $aluno->id, 'turma_id' => $turma->id, 'ano_lectivo_id' => $anoLectivo->id,
            'numero_registo_matricula' => '2026-0001', 'data_matricula' => '2026-02-01', 'estado' => EstadoMatriculaEnum::PENDENTE->value,
        ]);

        $this->expectException(ValidationException::class);

        try {
            (new EliminarAnoLectivoAction())->executar($anoLectivo);
        } finally {
            $this->assertDatabaseHas('ano_lectivos', ['id' => $anoLectivo->id, 'deleted_at' => null]);
        }
    }

    public function test_bloqueia_eliminacao_quando_existe_plano_curricular_associado(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        $anoLectivo = AnoLectivo::create([
            'estabelecimento_id' => $estabelecimento->id, 'nome' => '2024/2025',
            'data_inicio' => '2024-09-01', 'data_fim' => '2025-07-31', 'estado' => EstadoAnoLectivo::ENCERRADO->value,
        ]);
        $nivel = NivelAcademico::create([
            'estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1, 'etapa_ensino' => 1,
        ]);
        $plano = PlanoCurricular::create([
            'estabelecimento_id' => $estabelecimento->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'PL1', 'nome' => 'Plano 1',
        ]);
        PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $anoLectivo->id]);

        $this->expectException(ValidationException::class);

        try {
            (new EliminarAnoLectivoAction())->executar($anoLectivo);
        } finally {
            $this->assertDatabaseHas('ano_lectivos', ['id' => $anoLectivo->id, 'deleted_at' => null]);
        }
    }
}
