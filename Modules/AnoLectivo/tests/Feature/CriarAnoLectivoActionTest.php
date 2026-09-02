<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Actions\CriarAnoLectivoAction;
use Modules\AnoLectivo\DTO\AnoLectivoDTO;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\RoleSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class CriarAnoLectivoActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function actingAsStaff(): User
    {
        $staff = User::firstOrCreate(
            ['email' => 'staff@example.com'],
            ['name' => 'Staff', 'password' => Hash::make('segredo123')],
        );
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);

        $this->actingAs($staff);

        return $staff;
    }

    public function test_cria_ano_lectivo_associado_ao_estabelecimento_activo(): void
    {
        $this->actingAsStaff();
        $estabelecimento = Estabelecimento::create([
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => true,
        ]);

        $anoLectivo = (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
            nome: '2026/2027',
            dataInicio: '2026-09-01',
            dataFim: '2027-07-31',
            estado: EstadoAnoLectivo::ATIVO,
        ));

        $this->assertSame($estabelecimento->id, $anoLectivo->estabelecimento_id);
        $this->assertSame(EstadoAnoLectivo::ATIVO, $anoLectivo->estado);
    }

    public function test_bloqueia_segundo_ano_lectivo_activo_no_mesmo_estabelecimento(): void
    {
        $this->actingAsStaff();
        Estabelecimento::create([
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => true,
        ]);

        (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
            nome: '2026/2027',
            dataInicio: '2026-09-01',
            dataFim: '2027-07-31',
            estado: EstadoAnoLectivo::ATIVO,
        ));

        $this->expectException(ValidationException::class);

        try {
            (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
                nome: '2027/2028',
                dataInicio: '2027-09-01',
                dataFim: '2028-07-31',
                estado: EstadoAnoLectivo::ATIVO,
            ));
        } finally {
            $this->assertSame(1, AnoLectivo::where('estado', EstadoAnoLectivo::ATIVO->value)->count());
        }
    }

    public function test_permite_criar_ano_lectivo_planeado_com_outro_ja_activo(): void
    {
        $this->actingAsStaff();
        Estabelecimento::create([
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => true,
        ]);

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

        $this->assertSame(EstadoAnoLectivo::PLANEADO, $planeado->estado);
    }
}
