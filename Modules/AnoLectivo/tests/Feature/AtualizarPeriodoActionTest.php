<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Actions\AtualizarPeriodoAction;
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

class AtualizarPeriodoActionTest extends TestCase
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

    public function test_atualiza_datas_do_proprio_periodo_sem_conflito(): void
    {
        $periodo = (new CriarPeriodoAction())->criar($this->anoLectivo, new PeriodoDTO(
            nome: '1.º Trimestre',
            tipo: TipoPeriodo::TRIMESTRE,
            dataInicio: '2026-09-01',
            dataFim: '2026-12-15',
            numero: 1,
        ));

        $actualizado = (new AtualizarPeriodoAction())->atualizar($periodo, new PeriodoDTO(
            nome: '1.º Trimestre (ajustado)',
            tipo: TipoPeriodo::TRIMESTRE,
            dataInicio: '2026-09-05',
            dataFim: '2026-12-20',
            numero: 1,
        ));

        $this->assertSame('1.º Trimestre (ajustado)', $actualizado->nome);
    }

    public function test_rejeita_actualizacao_que_sobrepoe_outro_periodo(): void
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
            dataInicio: '2027-01-05',
            dataFim: '2027-03-31',
            numero: 2,
        ));

        $this->expectException(ValidationException::class);

        (new AtualizarPeriodoAction())->atualizar($segundo, new PeriodoDTO(
            nome: '2.º Trimestre',
            tipo: TipoPeriodo::TRIMESTRE,
            dataInicio: '2026-12-01',
            dataFim: '2027-03-31',
            numero: 2,
        ));
    }
}
