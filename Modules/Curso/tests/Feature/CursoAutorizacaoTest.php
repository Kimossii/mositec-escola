<?php

namespace Modules\Curso\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class CursoAutorizacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);
    }

    public function test_admin_escola_tem_permissao_em_curso(): void
    {
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);

        foreach (['ver', 'criar', 'editar'] as $acao) {
            $this->assertTrue(Gate::forUser($staff)->allows("curso.{$acao}"), "curso.{$acao}");
        }
    }

    public function test_professor_nao_tem_permissao_em_curso(): void
    {
        $professor = User::create(['name' => 'Professor', 'email' => 'professor@example.com', 'password' => Hash::make('x')]);
        $professor->roles()->syncWithoutDetaching([Role::where('nome', Perfil::PROFESSOR->value)->first()->id]);

        $this->assertFalse(Gate::forUser($professor)->allows('curso.ver'));
    }
}
