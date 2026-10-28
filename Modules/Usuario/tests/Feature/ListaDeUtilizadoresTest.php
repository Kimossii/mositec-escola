<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class ListaDeUtilizadoresTest extends TestCase
{
    use RefreshDatabase;

    private function utilizador(string $nome, string $email, Perfil $perfil, int $estado = 1): User
    {
        $user = User::create(['name' => $nome, 'email' => $email, 'password' => Hash::make('x'), 'estado' => $estado]);
        $user->roles()->attach(Role::where('nome', $perfil->value)->first()->id);

        return $user;
    }

    private function sessao(string $id, User $user, int $segundosAtras): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => '10.0.0.1', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time() - $segundosAtras]);
    }

    public function test_a_lista_traz_a_actividade_real_e_os_indicadores_diferidos(): void
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $admin = $this->utilizador('Admin', 'admin@example.com', Perfil::ADMIN_ESCOLA);
        $ana = $this->utilizador('Ana Aluna', 'ana@example.com', Perfil::ALUNO);
        $this->utilizador('Bruno Aluno', 'bruno@example.com', Perfil::ALUNO, 0);
        $this->utilizador('Carla Professora', 'carla@example.com', Perfil::PROFESSOR);
        $this->sessao('s-admin', $admin, 10);
        $this->sessao('s-ana', $ana, 7200);

        $this->actingAs($admin)->get('/usuarios/alunos')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('usuarios.data', 2)
            ->where('usuarios.data.0.name', 'Ana Aluna')
            ->where('usuarios.data.0.online', false)
            ->where('usuarios.data.0.ultimo_acesso', fn ($v) => $v !== null)
            ->where('usuarios.data.1.ultimo_acesso', null)
            ->missing('indicadores') // diferido
            ->loadDeferredProps(fn (Assert $c) => $c
                ->where('indicadores.total', 2)
                ->where('indicadores.ativos', 1)
                ->where('indicadores.inativos', 1)
                ->where('indicadores.online', 0) // a sessão da Ana é de há 2 h
                ->where('indicadores.por_perfil.alunos', 2)
                ->where('indicadores.por_perfil.professores', 1)
                ->where('indicadores.por_perfil.administradores', 1)
                ->where('indicadores.por_perfil.todos', 4)));

        $this->actingAs($admin)->get('/usuarios')->assertInertia(fn (Assert $page) => $page
            ->where('usuarios.data.0.name', 'Admin')
            ->where('usuarios.data.0.online', true)
            ->loadDeferredProps(fn (Assert $c) => $c->where('indicadores.online', 1)));
    }

    public function test_a_lista_mostra_a_foto_de_perfil_e_a_rota_respeita_as_permissoes(): void
    {
        \Illuminate\Support\Facades\Storage::fake('privado');
        $this->seed(PermissaoDatabaseSeeder::class);
        $admin = $this->utilizador('Admin', 'admin@example.com', Perfil::ADMIN_ESCOLA);
        $ana = $this->utilizador('Ana Aluna', 'ana@example.com', Perfil::ALUNO);
        $semFoto = $this->utilizador('Bruno Aluno', 'bruno@example.com', Perfil::ALUNO);

        $this->actingAs($ana)->post('/definicoes/foto', ['foto' => $this->imagemFalsa('a.png')]);
        $this->actingAs($admin)->post('/definicoes/foto', ['foto' => $this->imagemFalsa('b.png')]);

        $this->actingAs($admin)->get('/usuarios')->assertInertia(fn (Assert $page) => $page
            ->where('usuarios.data.0.name', 'Admin')
            ->where('usuarios.data.0.avatar', fn ($url) => str_starts_with($url, "/usuarios/{$admin->id}/foto?v="))
            ->where('usuarios.data.1.avatar', fn ($url) => str_starts_with($url, "/usuarios/{$ana->id}/foto?v="))
            ->where('usuarios.data.2.avatar', null));

        $this->actingAs($admin)->get("/usuarios/{$ana->id}/foto")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($admin)->get("/usuarios/{$semFoto->id}/foto")->assertNotFound();
        $this->actingAs($ana)->get("/usuarios/{$ana->id}/foto")->assertForbidden(); // aluno sem usuario.ver
    }
}
