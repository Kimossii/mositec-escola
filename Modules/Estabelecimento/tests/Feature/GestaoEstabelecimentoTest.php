<?php

namespace Modules\Estabelecimento\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\RoleSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class GestaoEstabelecimentoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => Hash::make('x')]);
        $admin->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id);
        $this->actingAs($admin);

        return $admin;
    }

    public function test_cria_o_estabelecimento_ao_atualizar_dados_pela_primeira_vez(): void
    {
        $this->actingAsAdmin();

        $response = $this->put('/estabelecimento', [
            'nome' => 'Escola Exemplo',
            'tipo' => TipoEstabelecimentoEnum::PRIVADO->value,
            'nif' => '5000123456',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('estabelecimentos', [
            'nome' => 'Escola Exemplo',
            'tipo' => TipoEstabelecimentoEnum::PRIVADO->value,
            'tipo_descricao' => 'Privado',
            'is_active' => true,
        ]);
    }

    public function test_atualiza_o_estabelecimento_atual_em_vez_de_duplicar(): void
    {
        $this->actingAsAdmin();

        Estabelecimento::create(['nome' => 'Escola Antiga', 'tipo' => TipoEstabelecimentoEnum::PUBLICO, 'is_active' => true]);

        $response = $this->put('/estabelecimento', [
            'nome' => 'Escola Renomeada',
            'tipo' => TipoEstabelecimentoEnum::COOPERATIVO->value,
        ]);

        $response->assertRedirect();
        $this->assertSame(1, Estabelecimento::count());
        $this->assertDatabaseHas('estabelecimentos', [
            'nome' => 'Escola Renomeada',
            'tipo' => TipoEstabelecimentoEnum::COOPERATIVO->value,
        ]);
    }

    public function test_utilizador_sem_permissao_nao_acede_ao_estabelecimento(): void
    {
        $semPermissao = User::create(['name' => 'Sem Permissao', 'email' => 'sem.permissao@example.com', 'password' => Hash::make('x')]);
        $this->actingAs($semPermissao);

        $response = $this->get('/estabelecimento');

        $response->assertForbidden();
    }

    public function test_pagina_dados_da_escola_devolve_o_estabelecimento_atual(): void
    {
        $this->actingAsAdmin();

        Estabelecimento::create(['nome' => 'Escola Exemplo', 'tipo' => TipoEstabelecimentoEnum::PUBLICO, 'is_active' => true]);

        $response = $this->get('/estabelecimento');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Estabelecimento/DadosDaEscola')
            ->where('estabelecimento.nome', 'Escola Exemplo')
        );
    }

    public function test_pagina_aparencia_devolve_o_estabelecimento_atual(): void
    {
        $this->actingAsAdmin();

        Estabelecimento::create(['nome' => 'Escola Exemplo', 'tipo' => TipoEstabelecimentoEnum::PUBLICO, 'is_active' => true]);

        $response = $this->get('/estabelecimento/aparencia');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Estabelecimento/Aparencia')
            ->where('estabelecimento.nome', 'Escola Exemplo')
        );
    }

    public function test_atualiza_o_logotipo_do_estabelecimento_atual(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();

        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Exemplo', 'tipo' => TipoEstabelecimentoEnum::PUBLICO, 'is_active' => true]);

        $response = $this->post('/estabelecimento/logotipo', [
            'logotipo' => UploadedFile::fake()->image('logo.png'),
        ]);

        $response->assertRedirect();
        $estabelecimento->refresh();
        $this->assertNotNull($estabelecimento->logotipo_path);
        Storage::disk('public')->assertExists($estabelecimento->logotipo_path);
    }

    public function test_nao_atualiza_logotipo_sem_estabelecimento_cadastrado(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();

        $response = $this->post('/estabelecimento/logotipo', [
            'logotipo' => UploadedFile::fake()->image('logo.png'),
        ]);

        $response->assertSessionHasErrors('estabelecimento');
    }
}
