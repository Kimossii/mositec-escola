<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Models\EventoCalendario;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Permissao\Database\Seeders\RoleSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AnoLectivoAutorizacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_escola_tem_permissao_gerir_ano_letivo(): void
    {
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);

        $this->assertTrue(Gate::forUser($staff)->allows('gerir-ano-letivo'));
        $this->assertTrue(Gate::forUser($staff)->allows('view', AnoLectivo::class));
        $this->assertTrue(Gate::forUser($staff)->allows('view', Periodo::class));
        $this->assertTrue(Gate::forUser($staff)->allows('view', EventoCalendario::class));
    }

    public function test_professor_nao_tem_permissao_gerir_ano_letivo(): void
    {
        $professor = User::create(['name' => 'Professor', 'email' => 'professor@example.com', 'password' => Hash::make('x')]);
        $professor->roles()->syncWithoutDetaching([Role::where('nome', Perfil::PROFESSOR->value)->first()->id]);

        $this->assertFalse(Gate::forUser($professor)->allows('gerir-ano-letivo'));
        $this->assertFalse(Gate::forUser($professor)->allows('view', AnoLectivo::class));
    }
}
