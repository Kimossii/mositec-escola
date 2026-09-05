<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Actions\EliminarAnoLectivoAction;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
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
}
