<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Actions\AlterarEstadoAnoLectivoAction;
use Modules\AnoLectivo\Actions\CriarAnoLectivoAction;
use Modules\AnoLectivo\DTO\AnoLectivoDTO;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AnoLectivoEstabelecimentoConsistenciaTest extends TestCase
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

    /**
     * Existe sempre um estabelecimento (o do tenant). Um Ano Lectivo criado ATIVO
     * nasce já com estabelecimento_id e, a partir daí, não é possível activar
     * um segundo Ano Lectivo no mesmo estabelecimento.
     * (O antigo cenário de backfill, com o ano criado antes de existir
     * estabelecimento, deixou de ser possível.)
     */
    public function test_ano_activo_nasce_com_estabelecimento_e_impede_segundo_ano_activo(): void
    {
        $estabelecimento = $this->estabelecimentoDeTeste();

        $anoA = (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
            nome: '2026/2027',
            dataInicio: '2026-09-01',
            dataFim: '2027-07-31',
            estado: EstadoAnoLectivo::ATIVO,
        ));

        $this->assertSame($estabelecimento->id, $anoA->estabelecimento_id);
        $this->assertSame(EstadoAnoLectivo::ATIVO, $anoA->estado);

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
}
