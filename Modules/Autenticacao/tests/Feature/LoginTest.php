<?php

namespace Modules\Autenticacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_view_the_login_page(): void
    {
        $response = $this->get('/login');

        $response->assertInertia(fn (Assert $page) => $page->component('Autenticacao/Login'));
    }

    public function test_login_page_does_not_use_the_authenticated_app_shell(): void
    {
        $response = $this->get('/login');

        $response->assertViewIs('layouts.guest');
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        User::create([
            'name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->post('/login', [
            'login' => 'ana@example.com',
            'password' => 'password123',
        ]);

        $response->assertRedirect('/');
        $this->assertTrue(Auth::check());
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        User::create([
            'name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->post('/login', [
            'login' => 'ana@example.com',
            'password' => 'senha-errada',
        ]);

        $response->assertSessionHasErrors('login');
        $this->assertFalse(Auth::check());
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::create([
            'name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirect('/login');
        $this->assertFalse(Auth::check());
    }

    public function test_guest_is_redirected_away_from_the_dashboard(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_is_redirected_away_from_the_login_page(): void
    {
        $user = User::create([
            'name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->actingAs($user)->get('/login');

        $response->assertRedirect('/');
    }

    public function test_login_forces_a_full_page_visit_on_an_inertia_request(): void
    {
        User::create([
            'name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->withHeaders(['X-Inertia' => 'true'])->post('/login', [
            'login' => 'ana@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(409);
        $response->assertHeader('X-Inertia-Location', '/');
    }

    public function test_logout_forces_a_full_page_visit_on_an_inertia_request(): void
    {
        $user = User::create([
            'name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->actingAs($user)
            ->withHeaders(['X-Inertia' => 'true'])
            ->post('/logout');

        $response->assertStatus(409);
        $response->assertHeader('X-Inertia-Location', '/login');
    }

    public function test_authenticated_user_is_shared_via_inertia(): void
    {
        $user = User::create([
            'name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->actingAs($user)->get('/');

        $response->assertInertia(
            fn (Assert $page) => $page->where('auth.user.email', 'ana@example.com')
        );
    }

    public function test_aluno_can_login_with_matricula(): void
    {
        User::create([
            'name' => 'Aluno Teste',
            'numero_matricula' => '2026-0001',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->post('/login', [
            'login' => '2026-0001',
            'password' => 'password123',
        ]);

        $response->assertRedirect('/');
        $this->assertTrue(Auth::check());
    }

    public function test_encarregado_logs_in_by_email_like_any_staff_account(): void
    {
        $filho = DadosPessoa::create(['nome_completo' => 'Filho', 'numero_identificacao' => 'BI-2', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);

        $encarregado = User::create([
            'name' => 'Encarregado Teste',
            'email' => 'encarregado@example.com',
            'password' => Hash::make('password123'),
        ]);
        $encarregado->educandos()->attach($filho->id);

        $response = $this->post('/login', [
            'login' => 'encarregado@example.com',
            'password' => 'password123',
        ]);

        $response->assertRedirect('/');
        $this->assertTrue(Auth::check());
        $this->assertTrue(Auth::user()->educandos->contains($filho));
    }

    public function test_conta_inexistente_demora_tanto_como_uma_existente_com_senha_errada(): void
    {
        // Sem igualar o tempo, um email inexistente responderia sem bcrypt e denunciaria que a conta não existe.
        $inicio = microtime(true);
        $this->post('/login', ['login' => 'nao-existe@example.com', 'password' => 'qualquer-coisa-123']);

        $this->assertGreaterThanOrEqual(0.15, microtime(true) - $inicio);
    }

    public function test_login_actualiza_o_hash_da_senha_quando_o_custo_mudou(): void
    {
        $user = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'senha-segura-123']);
        $hashAntigo = \Illuminate\Support\Facades\DB::table('users')->where('id', $user->id)->value('password');
        \Illuminate\Support\Facades\DB::table('users')->where('id', $user->id)->update(['password' => password_hash('senha-segura-123', PASSWORD_BCRYPT, ['cost' => 4])]);
        config(['hashing.bcrypt.rounds' => 12, 'hashing.bcrypt.verify' => false]);

        $this->post('/login', ['login' => 'ana@example.com', 'password' => 'senha-segura-123'])->assertRedirect('/');

        $novo = \Illuminate\Support\Facades\DB::table('users')->where('id', $user->id)->value('password');
        $this->assertStringStartsWith('$2y$12$', $novo);
    }
}
