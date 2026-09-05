<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Actions\AlterarEstadoAnoLectivoAction;
use Modules\AnoLectivo\Actions\CriarAnoLectivoAction;
use Modules\AnoLectivo\DTO\AnoLectivoDTO;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AlterarEstadoAnoLectivoActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($staff);

        Estabelecimento::create([
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => true,
        ]);
    }

    public function test_encerra_o_ano_lectivo_activo(): void
    {
        $anoLectivo = (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
            nome: '2025/2026',
            dataInicio: '2025-09-01',
            dataFim: '2026-07-31',
            estado: EstadoAnoLectivo::ATIVO,
        ));

        $encerrado = (new AlterarEstadoAnoLectivoAction())->alterar($anoLectivo, EstadoAnoLectivo::ENCERRADO);

        $this->assertSame(EstadoAnoLectivo::ENCERRADO, $encerrado->estado);
    }

    public function test_bloqueia_activar_planeado_quando_outro_ja_esta_activo(): void
    {
        (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
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

        (new AlterarEstadoAnoLectivoAction())->alterar($planeado, EstadoAnoLectivo::ATIVO);
    }

    public function test_activa_planeado_depois_de_encerrar_o_ativo_anterior(): void
    {
        $antigo = (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
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

        (new AlterarEstadoAnoLectivoAction())->alterar($antigo, EstadoAnoLectivo::ENCERRADO);
        $novoAtivo = (new AlterarEstadoAnoLectivoAction())->alterar($planeado, EstadoAnoLectivo::ATIVO);

        $this->assertSame(EstadoAnoLectivo::ATIVO, $novoAtivo->estado);
    }
}
