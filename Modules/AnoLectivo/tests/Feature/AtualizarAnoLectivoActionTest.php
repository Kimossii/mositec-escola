<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Actions\AtualizarAnoLectivoAction;
use Modules\AnoLectivo\Actions\CriarAnoLectivoAction;
use Modules\AnoLectivo\Actions\CriarPeriodoAction;
use Modules\AnoLectivo\DTO\AnoLectivoDTO;
use Modules\AnoLectivo\DTO\PeriodoDTO;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\RoleSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AtualizarAnoLectivoActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($staff);

        Estabelecimento::create([
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => true,
        ]);
    }

    public function test_atualiza_nome_e_datas(): void
    {
        $anoLectivo = (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
            nome: '2026/2027',
            dataInicio: '2026-09-01',
            dataFim: '2027-07-31',
            estado: EstadoAnoLectivo::PLANEADO,
        ));

        $actualizado = (new AtualizarAnoLectivoAction())->atualizar($anoLectivo, new AnoLectivoDTO(
            nome: '2026/2027 (revisto)',
            dataInicio: '2026-09-15',
            dataFim: '2027-07-31',
            estado: EstadoAnoLectivo::PLANEADO,
        ));

        $this->assertSame('2026/2027 (revisto)', $actualizado->nome);
        $this->assertSame('2026-09-15', $actualizado->data_inicio->toDateString());
    }

    public function test_bloqueia_activar_quando_outro_ja_esta_activo(): void
    {
        $ativo = (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
            nome: '2026/2027',
            dataInicio: '2026-09-01',
            dataFim: '2027-07-31',
            estado: EstadoAnoLectivo::ATIVO,
        ));

        $planeado = (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
            nome: '2027/2028',
            dataInicio: '2027-09-01',
            dataFim: '2028-07-31',
            estado: EstadoAnoLectivo::PLANEADO,
        ));

        $this->expectException(ValidationException::class);

        (new AtualizarAnoLectivoAction())->atualizar($planeado, new AnoLectivoDTO(
            nome: $planeado->nome,
            dataInicio: '2027-09-01',
            dataFim: '2028-07-31',
            estado: EstadoAnoLectivo::ATIVO,
        ));
    }

    public function test_permite_reactivar_o_proprio_ano_lectivo_ja_activo(): void
    {
        $ativo = (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
            nome: '2026/2027',
            dataInicio: '2026-09-01',
            dataFim: '2027-07-31',
            estado: EstadoAnoLectivo::ATIVO,
        ));

        $actualizado = (new AtualizarAnoLectivoAction())->atualizar($ativo, new AnoLectivoDTO(
            nome: '2026/2027',
            dataInicio: '2026-09-01',
            dataFim: '2027-07-31',
            estado: EstadoAnoLectivo::ATIVO,
        ));

        $this->assertSame(EstadoAnoLectivo::ATIVO, $actualizado->estado);
    }

    public function test_bloqueia_reduzir_intervalo_que_deixaria_periodo_fora(): void
    {
        $anoLectivo = (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
            nome: '2026/2027',
            dataInicio: '2026-09-01',
            dataFim: '2027-07-31',
            estado: EstadoAnoLectivo::PLANEADO,
        ));

        (new CriarPeriodoAction())->criar($anoLectivo, new PeriodoDTO(
            nome: '1.º Trimestre',
            tipo: TipoPeriodo::TRIMESTRE,
            dataInicio: '2026-09-01',
            dataFim: '2026-12-15',
            numero: 1,
        ));

        $this->expectException(ValidationException::class);

        try {
            (new AtualizarAnoLectivoAction())->atualizar($anoLectivo, new AnoLectivoDTO(
                nome: $anoLectivo->nome,
                dataInicio: '2026-10-01',
                dataFim: '2027-07-31',
                estado: EstadoAnoLectivo::PLANEADO,
            ));
        } finally {
            $anoLectivo->refresh();
            $this->assertSame('2026-09-01', $anoLectivo->data_inicio->toDateString());
        }
    }

    public function test_permite_reduzir_intervalo_quando_ainda_contem_todos_os_periodos(): void
    {
        $anoLectivo = (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
            nome: '2026/2027',
            dataInicio: '2026-09-01',
            dataFim: '2027-07-31',
            estado: EstadoAnoLectivo::PLANEADO,
        ));

        (new CriarPeriodoAction())->criar($anoLectivo, new PeriodoDTO(
            nome: '1.º Trimestre',
            tipo: TipoPeriodo::TRIMESTRE,
            dataInicio: '2026-09-15',
            dataFim: '2026-12-15',
            numero: 1,
        ));

        $actualizado = (new AtualizarAnoLectivoAction())->atualizar($anoLectivo, new AnoLectivoDTO(
            nome: $anoLectivo->nome,
            dataInicio: '2026-09-10',
            dataFim: '2027-01-31',
            estado: EstadoAnoLectivo::PLANEADO,
        ));

        $this->assertSame('2026-09-10', $actualizado->data_inicio->toDateString());
        $this->assertSame('2027-01-31', $actualizado->data_fim->toDateString());
    }
}
