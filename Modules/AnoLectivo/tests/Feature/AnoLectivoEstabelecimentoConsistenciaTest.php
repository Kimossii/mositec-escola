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
use Modules\Permissao\Database\Seeders\RoleSeeder;
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

        $this->seed(RoleSeeder::class);
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($staff);
    }

    /**
     * Cenário real: um Ano Lectivo é criado ATIVO antes de existir qualquer
     * Estabelecimento (estabelecimento_id fica NULL). Mais tarde cria-se o
     * primeiro Estabelecimento. Um simples toque de rotina no ano antigo
     * (ex.: reconfirmar o mesmo estado) deve fazer o backfill do
     * estabelecimento_id — e a partir daí já não deve ser possível activar
     * um segundo Ano Lectivo no mesmo estabelecimento.
     */
    public function test_backfill_do_estabelecimento_impede_dois_anos_activos_apos_criacao_tardia_do_estabelecimento(): void
    {
        $anoA = (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
            nome: '2026/2027',
            dataInicio: '2026-09-01',
            dataFim: '2027-07-31',
            estado: EstadoAnoLectivo::ATIVO,
        ));

        $this->assertNull($anoA->estabelecimento_id);
        $this->assertSame(EstadoAnoLectivo::ATIVO, $anoA->estado);

        $estabelecimento = Estabelecimento::create([
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => true,
        ]);

        // Toque de rotina em anoA (mesma acção/estado) deve fazer o backfill
        // do estabelecimento_id, autocorrigindo o registo.
        $anoA = (new AlterarEstadoAnoLectivoAction())->alterar($anoA, EstadoAnoLectivo::ATIVO);
        $this->assertSame($estabelecimento->id, $anoA->estabelecimento_id);

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
