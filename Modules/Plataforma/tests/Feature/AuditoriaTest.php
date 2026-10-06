<?php

namespace Modules\Plataforma\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Modules\Plataforma\Actions\RegistarAuditoriaAction;
use Modules\Plataforma\Exceptions\DetalheDeAuditoriaInvalido;
use Modules\Plataforma\Models\RegistoDeAuditoria;
use Modules\Plataforma\Models\SuperAdmin;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuditoriaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): SuperAdmin
    {
        return SuperAdmin::create(['name' => 'Op', 'email' => 'op@plataforma.test', 'password' => 'x']);
    }

    public function test_grava_autor_accao_codigo_do_tenant_detalhe_e_ip(): void
    {
        $this->app->instance('request', Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.9']));
        $admin = $this->admin();

        app(RegistarAuditoriaAction::class)->executar($admin, 'escola.suspensa', 'MOSI-000007', ['motivo' => 'falta de pagamento']);

        $registo = RegistoDeAuditoria::query()->sole();
        $this->assertSame($admin->id, $registo->super_admin_id);
        $this->assertSame('escola.suspensa', $registo->accao);
        $this->assertSame('MOSI-000007', $registo->codigo_tenant);
        $this->assertSame(['motivo' => 'falta de pagamento'], $registo->detalhe);
        $this->assertSame('203.0.113.9', $registo->ip);
        $this->assertNotNull($registo->created_at);
    }

    public function test_aceita_accao_sem_autor_sem_tenant_e_sem_detalhe(): void
    {
        app(RegistarAuditoriaAction::class)->executar(null, 'plataforma.arranque', null);

        $registo = RegistoDeAuditoria::query()->sole();
        $this->assertNull($registo->super_admin_id);
        $this->assertNull($registo->codigo_tenant);
        $this->assertNull($registo->detalhe);
    }

    public function test_o_registo_sobrevive_a_remocao_do_autor_e_nao_tem_updated_at(): void
    {
        $admin = $this->admin();
        app(RegistarAuditoriaAction::class)->executar($admin, 'escola.criada', 'MOSI-000008');

        $admin->delete();

        $registo = RegistoDeAuditoria::query()->sole();
        $this->assertNull($registo->super_admin_id);
        $this->assertArrayNotHasKey('updated_at', $registo->getAttributes());
    }

    public static function chavesProibidas(): array
    {
        return [
            'senha' => [['senha' => 'x']],
            'password' => [['password' => 'x']],
            'token' => [['token' => 'x']],
            'credencial' => [['credencial' => 'x']],
            'secret' => [['secret' => 'x']],
            'senha_temporaria' => [['senha_temporaria' => 'x']],
            'password_confirmation' => [['password_confirmation' => 'x']],
            'current_password' => [['current_password' => 'x']],
            'new_password' => [['new_password' => 'x']],
            'remember_token' => [['remember_token' => 'x']],
            'senha_temporaria aninhada' => [['escola' => ['Senha_Temporaria' => 'x']]],
            'maiúsculas' => [['PassWord' => 'x']],
            'aninhada' => [['escola' => ['admin' => ['Senha' => 'x']]]],
            'numa lista' => [['itens' => [['ok' => 1], ['token' => 'x']]]],
        ];
    }

    #[DataProvider('chavesProibidas')]
    public function test_recusa_detalhe_com_chaves_proibidas_em_qualquer_nivel(array $detalhe): void
    {
        try {
            app(RegistarAuditoriaAction::class)->executar(null, 'escola.criada', 'MOSI-000001', $detalhe);
            $this->fail('Devia recusar o detalhe.');
        } catch (DetalheDeAuditoriaInvalido $e) {
            $this->assertStringNotContainsString('"x"', $e->getMessage());
        }

        $this->assertSame(0, RegistoDeAuditoria::count());
    }

    public function test_chaves_inofensivas_passam(): void
    {
        app(RegistarAuditoriaAction::class)->executar(null, 'escola.criada', 'MOSI-000001', ['email' => 'a@b.test', 'dominio' => 'x.test', 'tokens_revogados' => 3, 'senhas_revogadas' => 1, 'tem_senha_temporaria' => true]);

        $this->assertSame(1, RegistoDeAuditoria::count());
    }

    public function test_recusa_accao_vazia(): void
    {
        $this->expectException(DetalheDeAuditoriaInvalido::class);

        app(RegistarAuditoriaAction::class)->executar(null, '  ', null);
    }
}
