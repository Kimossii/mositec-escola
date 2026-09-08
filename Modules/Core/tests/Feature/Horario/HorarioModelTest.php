<?php

namespace Modules\Core\Tests\Feature\Horario;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Enums\Estado;
use Modules\Core\Models\Horario;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class HorarioModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);
    }

    public function test_sincroniza_estado_descricao_ao_criar(): void
    {
        $horario = Horario::create([
            'nome' => 'Manhã',
            'hora_inicio' => '08:00',
            'hora_fim' => '12:00',
            'estado' => Estado::ATIVO->value,
        ]);

        $this->assertSame('Ativo', $horario->estado_descricao);
    }

    public function test_sincroniza_estado_descricao_ao_alterar_para_inativo(): void
    {
        $horario = Horario::create([
            'nome' => 'Manhã',
            'hora_inicio' => '08:00',
            'hora_fim' => '12:00',
            'estado' => Estado::ATIVO->value,
        ]);

        $horario->update(['estado' => Estado::INATIVO->value]);

        $this->assertSame('Inativo', $horario->fresh()->estado_descricao);
    }

    public function test_regista_autoria_ao_criar_e_actualizar(): void
    {
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => 'x']);
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($staff);

        $horario = Horario::create([
            'nome' => 'Manhã',
            'hora_inicio' => '08:00',
            'hora_fim' => '12:00',
        ]);

        $this->assertSame($staff->id, $horario->criado_por);
        $this->assertSame($staff->id, $horario->editado_por);

        $outro = User::create(['name' => 'Outro', 'email' => 'outro@example.com', 'password' => 'x']);
        $this->actingAs($outro);
        $horario->update(['nome' => 'Manhã (actualizado)']);

        $this->assertSame($staff->id, $horario->fresh()->criado_por);
        $this->assertSame($outro->id, $horario->fresh()->editado_por);
    }
}
