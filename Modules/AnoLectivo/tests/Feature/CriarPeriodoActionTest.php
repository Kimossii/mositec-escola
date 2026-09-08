<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Actions\CriarPeriodoAction;
use Modules\AnoLectivo\DTO\PeriodoDTO;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class CriarPeriodoActionTest extends TestCase
{
    use RefreshDatabase;

    private AnoLectivo $anoLectivo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($staff);

        $this->anoLectivo = AnoLectivo::create([
            'nome' => '2026/2027',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'estado' => EstadoAnoLectivo::ATIVO->value,
        ]);
    }

    public function test_cria_periodo_dentro_do_intervalo(): void
    {
        $periodo = (new CriarPeriodoAction())->criar($this->anoLectivo, new PeriodoDTO(
            nome: '1.º Trimestre',
            tipo: TipoPeriodo::TRIMESTRE,
            dataInicio: '2026-09-01',
            dataFim: '2026-12-15',
            numero: 1,
        ));

        $this->assertDatabaseHas('periodos', ['id' => $periodo->id, 'nome' => '1.º Trimestre']);
    }

    public function test_rejeita_periodo_fora_do_intervalo_do_ano_lectivo(): void
    {
        $this->expectException(ValidationException::class);

        (new CriarPeriodoAction())->criar($this->anoLectivo, new PeriodoDTO(
            nome: 'Fora do intervalo',
            tipo: TipoPeriodo::TRIMESTRE,
            dataInicio: '2026-08-01',
            dataFim: '2026-08-31',
            numero: 1,
        ));
    }

    public function test_rejeita_periodos_sobrepostos(): void
    {
        (new CriarPeriodoAction())->criar($this->anoLectivo, new PeriodoDTO(
            nome: '1.º Trimestre',
            tipo: TipoPeriodo::TRIMESTRE,
            dataInicio: '2026-09-01',
            dataFim: '2026-12-15',
            numero: 1,
        ));

        $this->expectException(ValidationException::class);

        (new CriarPeriodoAction())->criar($this->anoLectivo, new PeriodoDTO(
            nome: '2.º Trimestre',
            tipo: TipoPeriodo::TRIMESTRE,
            dataInicio: '2026-12-01',
            dataFim: '2027-03-31',
            numero: 2,
        ));
    }

    public function test_aceita_periodo_com_data_fim_igual_a_data_fim_do_ano_lectivo(): void
    {
        $periodo = (new CriarPeriodoAction())->criar($this->anoLectivo, new PeriodoDTO(
            nome: '3.º Trimestre',
            tipo: TipoPeriodo::TRIMESTRE,
            dataInicio: '2027-04-05',
            dataFim: '2027-07-31',
            numero: 3,
        ));

        $this->assertDatabaseHas('periodos', ['id' => $periodo->id, 'nome' => '3.º Trimestre']);
    }

    public function test_aceita_periodo_com_data_nao_padronizada_dentro_do_intervalo(): void
    {
        $anoCurto = AnoLectivo::create([
            'nome' => 'Ano Curto',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2026-10-01',
            'estado' => EstadoAnoLectivo::PLANEADO->value,
        ]);

        // '2026-9-5' (Set. 5) não é zero-padded; comparado como string,
        // '2026-9-5' > '2026-10-01' é verdadeiro (bug), apesar de Set. 5
        // ser antes de Out. 1 no calendário real.
        $periodo = (new CriarPeriodoAction())->criar($anoCurto, new PeriodoDTO(
            nome: 'Período único',
            tipo: TipoPeriodo::OUTRO,
            dataInicio: '2026-09-01',
            dataFim: '2026-9-5',
            numero: 1,
        ));

        $this->assertDatabaseHas('periodos', ['id' => $periodo->id, 'nome' => 'Período único']);
    }

    public function test_rejeita_periodo_com_data_nao_padronizada_fora_do_intervalo(): void
    {
        $anoCurto = AnoLectivo::create([
            'nome' => 'Ano Curto',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2026-10-01',
            'estado' => EstadoAnoLectivo::PLANEADO->value,
        ]);

        $this->expectException(ValidationException::class);

        (new CriarPeriodoAction())->criar($anoCurto, new PeriodoDTO(
            nome: 'Fora do intervalo',
            tipo: TipoPeriodo::OUTRO,
            dataInicio: '2026-10-2',
            dataFim: '2026-11-3',
            numero: 1,
        ));
    }

    public function test_aceita_periodos_adjacentes_sem_sobreposicao(): void
    {
        $primeiro = (new CriarPeriodoAction())->criar($this->anoLectivo, new PeriodoDTO(
            nome: '1.º Trimestre',
            tipo: TipoPeriodo::TRIMESTRE,
            dataInicio: '2026-09-01',
            dataFim: '2026-12-15',
            numero: 1,
        ));

        $segundo = (new CriarPeriodoAction())->criar($this->anoLectivo, new PeriodoDTO(
            nome: '2.º Trimestre',
            tipo: TipoPeriodo::TRIMESTRE,
            dataInicio: '2026-12-16',
            dataFim: '2027-03-31',
            numero: 2,
        ));

        $this->assertDatabaseHas('periodos', ['id' => $primeiro->id, 'nome' => '1.º Trimestre']);
        $this->assertDatabaseHas('periodos', ['id' => $segundo->id, 'nome' => '2.º Trimestre']);
    }
}
