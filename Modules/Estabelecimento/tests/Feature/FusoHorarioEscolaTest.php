<?php

namespace Modules\Estabelecimento\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Enums\TipoEnsinoEnum;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class FusoHorarioEscolaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function utilizador(Perfil $perfil): User
    {
        $user = User::create(['name' => $perfil->value, 'email' => $perfil->value . '@example.com', 'password' => Hash::make('x')]);
        $user->roles()->attach(Role::where('nome', $perfil->value)->first()->id);

        return $user;
    }

    private function dados(array $extra = []): array
    {
        return array_merge([
            'nome' => 'Escola Exemplo',
            'tipo' => TipoEstabelecimentoEnum::PRIVADO->value,
            'tipo_ensino' => TipoEnsinoEnum::GERAL->value,
            'etapas_ensino' => [EtapaEnsinoEnum::PRIMARIO->value],
        ], $extra);
    }

    public function test_fuso_invalido_e_rejeitado(): void
    {
        $this->actingAs($this->utilizador(Perfil::ADMIN_ESCOLA));

        foreach (['Marte/Olympus', 'luanda', 'GMT+1', '', 123] as $invalido) {
            $this->put('/estabelecimento', $this->dados(['fuso_horario' => $invalido]))->assertSessionHasErrors('fuso_horario');
        }

        $this->assertSame('Africa/Luanda', Estabelecimento::current()->fuso_horario);
    }

    public function test_fuso_valido_e_aceite_e_gravado(): void
    {
        $this->actingAs($this->utilizador(Perfil::ADMIN_ESCOLA));

        $this->put('/estabelecimento', $this->dados(['fuso_horario' => 'Europe/Lisbon']))
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('estabelecimentos', ['fuso_horario' => 'Europe/Lisbon']);

        $this->put('/estabelecimento', $this->dados(['fuso_horario' => 'UTC']))->assertSessionHasNoErrors();
        $this->assertSame('UTC', Estabelecimento::current()->fuso_horario);
    }

    public function test_sem_o_campo_o_fuso_existente_mantem_se(): void
    {
        $this->actingAs($this->utilizador(Perfil::ADMIN_ESCOLA));
        Estabelecimento::current()->update(['fuso_horario' => 'Europe/Lisbon']);

        $this->put('/estabelecimento', $this->dados())->assertSessionHasNoErrors();

        $this->assertSame('Europe/Lisbon', Estabelecimento::current()->fuso_horario);
    }

    public function test_a_pagina_expoe_o_fuso_e_a_lista_com_luanda_no_topo(): void
    {
        $this->actingAs($this->utilizador(Perfil::ADMIN_ESCOLA))
            ->get('/estabelecimento')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Estabelecimento/DadosDaEscola')
                ->where('estabelecimento.fuso_horario', 'Africa/Luanda')
                ->where('fusosHorarios.0', 'Africa/Luanda')
                ->where('fusosHorarios', fn ($lista) => collect($lista)->contains('Europe/Lisbon')
                    && collect($lista)->contains('UTC')
                    && collect($lista)->filter(fn ($f) => $f === 'Africa/Luanda')->count() === 1));
    }

    public function test_o_fuso_gravado_volta_na_pagina(): void
    {
        $admin = $this->utilizador(Perfil::ADMIN_ESCOLA);
        $this->actingAs($admin)->put('/estabelecimento', $this->dados(['fuso_horario' => 'America/Sao_Paulo']));

        $this->get('/estabelecimento')->assertInertia(fn (Assert $page) => $page->where('estabelecimento.fuso_horario', 'America/Sao_Paulo'));
    }

    public function test_permissoes_nao_mudam(): void
    {
        $this->actingAs($this->utilizador(Perfil::PROFESSOR))
            ->put('/estabelecimento', $this->dados(['fuso_horario' => 'UTC']))
            ->assertForbidden();

        $this->assertSame('Africa/Luanda', Estabelecimento::current()->fuso_horario);
    }
}
