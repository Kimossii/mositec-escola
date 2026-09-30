<?php

namespace Modules\Matricula\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Matricula\Http\Requests\RenovarMatriculasEmMassaRequest;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class RenovarMatriculasEmMassaRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function actingAsStaff(): void
    {
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('segredo123')]);
        $staff->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id);
        $this->actingAs($staff);
    }

    // Um pedido síncrono com uma lista arbitrariamente grande de ids
    // arrisca o timeout do request — o limite mantém a renovação em massa
    // dentro do que o pedido síncrono aguenta em segurança.
    public function test_rejeita_mais_de_200_matriculas_de_uma_vez(): void
    {
        $this->actingAsStaff();

        $resposta = $this->post('/matriculas/renovar-em-massa', [
            'matricula_ids' => range(1, 201),
        ]);

        $resposta->assertSessionHasErrors('matricula_ids');
    }

    public function test_aceita_ate_200_matriculas(): void
    {
        $request = new RenovarMatriculasEmMassaRequest();
        $regras = $request->rules();

        $this->assertContains('max:200', $regras['matricula_ids']);
    }
}
