<?php

namespace Modules\Autenticacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Autenticacao\Tests\Support\ComDoisFactores;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class DoisFactoresApiTest extends TestCase
{
    use RefreshDatabase;
    use ComDoisFactores;

    private const LOGIN = '/api/v1/autenticacaoApi/api/login';
    private const DESAFIO = '/api/v1/autenticacaoApi/api/2fa';

    private function utilizador(bool $comDoisFactores): User
    {
        $user = User::create(['name' => 'Ana Silva', 'email' => 'ana@example.com', 'password' => Hash::make('senha-segura-123')]);

        return $comDoisFactores ? $this->comDoisFactores($user) : $user;
    }

    private function desafio(): string
    {
        $resposta = $this->postJson(self::LOGIN, ['email' => 'ana@example.com', 'password' => 'senha-segura-123']);
        $resposta->assertStatus(202)->assertJsonPath('requer_2fa', true);

        return $resposta->json('desafio');
    }

    public function test_login_sem_2fa_continua_igual(): void
    {
        $this->utilizador(false);

        $this->postJson(self::LOGIN, ['email' => 'ana@example.com', 'password' => 'senha-segura-123'])
            ->assertOk()->assertJsonStructure(['token', 'user']);
    }

    public function test_login_com_2fa_devolve_desafio_e_nenhum_token(): void
    {
        $this->utilizador(true);

        $resposta = $this->postJson(self::LOGIN, ['email' => 'ana@example.com', 'password' => 'senha-segura-123']);

        $resposta->assertStatus(202)->assertJsonPath('requer_2fa', true)->assertJsonMissingPath('token')->assertJsonMissingPath('user');
        $this->assertSame(40, strlen($resposta->json('desafio')));
        $this->assertSame(0, $this->utilizador_tokens());
    }

    private function utilizador_tokens(): int
    {
        return User::where('email', 'ana@example.com')->first()->tokens()->count();
    }

    public function test_desafio_com_codigo_valido_emite_token(): void
    {
        $ana = $this->utilizador(true);
        $desafio = $this->desafio();

        $this->postJson(self::DESAFIO, ['desafio' => $desafio, 'code' => $this->codigoTotp($ana)])
            ->assertOk()->assertJsonStructure(['token', 'user']);
        $this->assertSame(1, $this->utilizador_tokens());
    }

    public function test_desafio_e_de_uso_unico(): void
    {
        $ana = $this->utilizador(true);
        $desafio = $this->desafio();
        $this->postJson(self::DESAFIO, ['desafio' => $desafio, 'code' => $this->codigoTotp($ana)])->assertOk();

        $this->postJson(self::DESAFIO, ['desafio' => $desafio, 'code' => $this->codigoTotp($ana)])->assertStatus(422);
    }

    public function test_codigo_errado_e_422_e_a_sexta_tentativa_e_429(): void
    {
        $this->utilizador(true);
        $desafio = $this->desafio();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::DESAFIO, ['desafio' => $desafio, 'code' => '000000'])->assertStatus(422);
        }

        $this->postJson(self::DESAFIO, ['desafio' => $desafio, 'code' => '000000'])->assertStatus(429);
        $this->assertSame(0, $this->utilizador_tokens());
    }

    public function test_desafio_expirado_e_422(): void
    {
        $ana = $this->utilizador(true);
        $desafio = $this->desafio();

        $this->travel(6)->minutes();

        $this->postJson(self::DESAFIO, ['desafio' => $desafio, 'code' => $this->codigoTotp($ana)])->assertStatus(422);
    }

    public function test_desafio_de_outro_tenant_e_422(): void
    {
        $ana = $this->utilizador(true);
        $desafio = $this->desafio();
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        $this->postJson($this->urlDoTenant($outro, self::DESAFIO), ['desafio' => $desafio, 'code' => $this->codigoTotp($ana)])->assertStatus(422);
    }

    public function test_codigo_de_recuperacao_so_funciona_uma_vez(): void
    {
        $this->utilizador(true);

        $this->postJson(self::DESAFIO, ['desafio' => $this->desafio(), 'recovery_code' => 'recup-0001'])->assertOk()->assertJsonStructure(['token']);

        $this->postJson(self::DESAFIO, ['desafio' => $this->desafio(), 'recovery_code' => 'recup-0001'])->assertStatus(422);
    }

    public function test_conta_inactiva_entre_passos_e_403(): void
    {
        $ana = $this->utilizador(true);
        $desafio = $this->desafio();
        $ana->forceFill(['estado' => 0])->save();

        $this->postJson(self::DESAFIO, ['desafio' => $desafio, 'code' => $this->codigoTotp($ana)])->assertStatus(403);
        $this->assertSame(0, $this->utilizador_tokens());
    }

    public function test_pedido_sem_codigo_nem_recuperacao_e_invalido(): void
    {
        $this->postJson(self::DESAFIO, ['desafio' => str_repeat('a', 40)])->assertStatus(422)->assertJsonValidationErrors(['code']);
    }

    public function test_pedir_desafios_novos_nao_reinicia_o_limite_de_tentativas(): void
    {
        $ana = $this->utilizador(true);
        $primeiro = $this->desafio();
        $segundo = $this->desafio();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::DESAFIO, ['desafio' => $primeiro, 'code' => '000000'])->assertStatus(422);
        }

        // O limite é da conta: o outro desafio já emitido também fica bloqueado, mesmo com o código certo...
        $this->postJson(self::DESAFIO, ['desafio' => $segundo, 'code' => $this->codigoTotp($ana)])->assertStatus(429);
        // ...e já não se emitem desafios novos enquanto durar o bloqueio.
        $this->postJson(self::LOGIN, ['email' => 'ana@example.com', 'password' => 'senha-segura-123'])
            ->assertStatus(429)->assertJsonMissingPath('desafio');
        $this->assertSame(0, $this->utilizador_tokens());
    }
}
