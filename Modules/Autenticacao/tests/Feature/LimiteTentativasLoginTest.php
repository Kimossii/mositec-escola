<?php

namespace Modules\Autenticacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class LimiteTentativasLoginTest extends TestCase
{
    use RefreshDatabase;

    private function criarUtilizador(string $email = 'ana@example.com'): User
    {
        return User::create([
            'name' => 'Utilizador',
            'email' => $email,
            'password' => Hash::make('password123'),
        ]);
    }

    private function tentarLogin(string $login, string $password, string $ip = '10.0.0.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post('/login', ['login' => $login, 'password' => $password]);
    }

    private function falhar(string $login, int $vezes, string $ip = '10.0.0.1'): void
    {
        for ($i = 0; $i < $vezes; $i++) {
            $this->tentarLogin($login, 'errada', $ip);
        }
    }

    public function test_bloqueia_a_conta_neste_ip_apos_cinco_falhas_mesmo_com_a_senha_certa(): void
    {
        $this->criarUtilizador();

        $this->falhar('ana@example.com', 5);
        $resposta = $this->tentarLogin('ana@example.com', 'password123');

        $resposta->assertSessionHasErrors('login');
        $this->assertFalse(Auth::check());
    }

    public function test_falhas_numa_conta_nao_bloqueiam_outra_conta_no_mesmo_ip(): void
    {
        $this->criarUtilizador('ana@example.com');
        $this->criarUtilizador('rui@example.com');

        $this->falhar('ana@example.com', 5);
        $resposta = $this->tentarLogin('rui@example.com', 'password123');

        $resposta->assertRedirect('/');
        $this->assertTrue(Auth::check());
    }

    public function test_o_identificador_e_normalizado_para_maiusculas_e_espacos(): void
    {
        $this->criarUtilizador();

        $this->falhar('ANA@example.com', 3);
        $this->falhar('  ana@EXAMPLE.com ', 2);
        $resposta = $this->tentarLogin('ana@example.com', 'password123');

        $resposta->assertSessionHasErrors('login');
        $this->assertFalse(Auth::check());
    }

    public function test_ataque_distribuido_a_uma_conta_e_bloqueado_por_limite_global_da_conta(): void
    {
        $this->criarUtilizador();

        // 5 IPs diferentes, 4 falhas cada (abaixo do limite por conta+ip) = 20 falhas.
        foreach (['10.0.1.1', '10.0.1.2', '10.0.1.3', '10.0.1.4', '10.0.1.5'] as $ip) {
            $this->falhar('ana@example.com', 4, $ip);
        }

        $resposta = $this->tentarLogin('ana@example.com', 'password123', '10.0.9.9');

        $resposta->assertSessionHasErrors('login');
        $this->assertFalse(Auth::check());
    }

    public function test_pulverizacao_de_muitas_contas_a_partir_de_um_ip_e_bloqueada_por_ip(): void
    {
        $this->criarUtilizador('ana@example.com');

        for ($i = 0; $i < 30; $i++) {
            $this->tentarLogin("inexistente{$i}@example.com", 'errada', '10.0.2.2');
        }

        $resposta = $this->tentarLogin('ana@example.com', 'password123', '10.0.2.2');

        $resposta->assertSessionHasErrors('login');
        $this->assertFalse(Auth::check());
    }

    public function test_login_com_sucesso_repoe_o_contador_da_conta(): void
    {
        $this->criarUtilizador();

        $this->falhar('ana@example.com', 4);
        $this->tentarLogin('ana@example.com', 'password123')->assertRedirect('/');
        Auth::logout();

        $this->falhar('ana@example.com', 4);
        $resposta = $this->tentarLogin('ana@example.com', 'password123');

        $resposta->assertRedirect('/');
        $this->assertTrue(Auth::check());
    }

    public function test_a_mensagem_de_bloqueio_indica_o_tempo_de_espera(): void
    {
        $this->criarUtilizador();

        $this->falhar('ana@example.com', 5);
        $resposta = $this->tentarLogin('ana@example.com', 'password123');

        $this->assertStringContainsString(
            'Muitas tentativas de login',
            session('errors')->first('login'),
        );
        $resposta->assertSessionHasErrors('login');
    }

    public function test_api_bloqueia_a_conta_neste_ip_apos_cinco_falhas(): void
    {
        $this->criarUtilizador();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/autenticacaoApi/api/login', [
                'email' => 'ana@example.com',
                'password' => 'errada1',
            ])->assertStatus(401);
        }

        $this->postJson('/api/v1/autenticacaoApi/api/login', [
            'email' => 'ana@example.com',
            'password' => 'password123',
        ])->assertStatus(429);
    }

    public function test_api_falhas_numa_conta_nao_bloqueiam_outra_no_mesmo_ip(): void
    {
        $this->criarUtilizador('ana@example.com');
        $this->criarUtilizador('rui@example.com');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/autenticacaoApi/api/login', [
                'email' => 'ana@example.com',
                'password' => 'errada1',
            ]);
        }

        $this->postJson('/api/v1/autenticacaoApi/api/login', [
            'email' => 'rui@example.com',
            'password' => 'password123',
        ])->assertStatus(200);
    }
}
