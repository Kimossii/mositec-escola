<?php

namespace Modules\Autenticacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Autenticacao\Service\SessoesDoUtilizadorService;
use Modules\Autenticacao\Support\DescricaoDoDispositivo;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class DefinicoesTest extends TestCase
{
    use RefreshDatabase;

    private function utilizador(string $email): User
    {
        return User::create(['name' => 'Ana Silva', 'email' => $email, 'password' => Hash::make('x')]);
    }

    private function sessao(string $id, ?int $userId, string $userAgent = 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36'): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'ip_address' => '10.0.0.1', 'user_agent' => $userAgent, 'payload' => '', 'last_activity' => time()]);
    }

    public function test_definicoes_mostra_as_sessoes_do_proprio_utilizador_sem_expor_identificadores(): void
    {
        $ana = $this->utilizador('ana@example.com');
        $outro = $this->utilizador('outro@example.com');
        $this->sessao('sessao-ana', $ana->id);
        $this->sessao('sessao-outro', $outro->id);

        $this->actingAs($ana)->get('/definicoes?aba=seguranca')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Autenticacao/Definicoes')
            ->where('aba', 'seguranca')
            ->has('sessoes', 1)
            ->where('sessoes.0.navegador', 'Chrome')
            ->where('sessoes.0.sistema', 'Windows')
            ->missing('sessoes.0.id'));
    }

    public function test_aba_invalida_cai_no_perfil_e_exige_autenticacao(): void
    {
        $this->actingAs($this->utilizador('ana@example.com'))->get('/definicoes?aba=xpto')->assertInertia(fn (Assert $page) => $page->where('aba', 'perfil'));
        auth()->logout();
        $this->get('/definicoes')->assertRedirect('/login');
    }

    public function test_terminar_outras_sessoes_so_apaga_as_do_proprio_utilizador(): void
    {
        $ana = $this->utilizador('ana@example.com');
        $outro = $this->utilizador('outro@example.com');
        $this->sessao('a1', $ana->id);
        $this->sessao('a2', $ana->id);
        $this->sessao('o1', $outro->id);

        $this->actingAs($ana)->post('/definicoes/sessoes/terminar-outras')->assertRedirect();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $ana->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $outro->id)->count());
    }

    public function test_terminar_uma_sessao_pela_referencia_opaca(): void
    {
        $ana = $this->utilizador('ana@example.com');
        $outro = $this->utilizador('outro@example.com');
        $this->sessao('a1', $ana->id);
        $this->sessao('o1', $outro->id);

        $this->actingAs($ana)->get('/definicoes')->assertInertia(fn (Assert $page) => $page
            ->where('sessoes.0.referencia', SessoesDoUtilizadorService::referencia('a1')));

        // Não se termina a sessão de outro utilizador, nem com a referência certa dessa sessão.
        $this->actingAs($ana)->delete('/definicoes/sessoes/'.SessoesDoUtilizadorService::referencia('o1'))->assertNotFound();
        $this->assertSame(1, DB::table('sessions')->where('id', 'o1')->count());

        $this->actingAs($ana)->delete('/definicoes/sessoes/'.SessoesDoUtilizadorService::referencia('a1'))->assertRedirect();
        $this->assertSame(0, DB::table('sessions')->where('id', 'a1')->count());

        $this->actingAs($ana)->delete('/definicoes/sessoes/'.str_repeat('a', 64))->assertNotFound();
    }

    public function test_descricao_do_dispositivo_reconhece_navegador_e_sistema(): void
    {
        $this->assertSame(['navegador' => 'Edge', 'sistema' => 'Windows', 'tipo' => 'computador'], DescricaoDoDispositivo::de('Mozilla/5.0 (Windows NT 10.0) Chrome/120 Safari/537 Edg/120'));
        $this->assertSame(['navegador' => 'Safari', 'sistema' => 'iOS', 'tipo' => 'telemovel'], DescricaoDoDispositivo::de('Mozilla/5.0 (iPhone; CPU iPhone OS 17) AppleWebKit Safari/604'));
        $this->assertSame('Navegador desconhecido', DescricaoDoDispositivo::de(null)['navegador']);
    }
}
