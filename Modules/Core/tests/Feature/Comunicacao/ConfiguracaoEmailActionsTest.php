<?php

namespace Modules\Core\Tests\Feature\Comunicacao;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Core\Actions\Email\DesactivarConfiguracaoEmailAction;
use Modules\Core\Actions\Email\GuardarConfiguracaoEmailAction;
use Modules\Core\Actions\Email\TestarConfiguracaoEmailAction;
use Modules\Core\Comunicacao\Email\EstadoConfiguracaoEmail;
use Modules\Core\Models\AuditoriaTenant;
use Modules\Core\Models\ConfiguracaoEmail;
use RuntimeException;
use Tests\TestCase;

class ConfiguracaoEmailActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Nunca há rede nos testes: o servidor «resolve» sempre para um IP público.
        $this->app->bind(\Modules\Core\Comunicacao\Email\ValidadorDeServidorSmtp::class, fn () => new \Modules\Core\Comunicacao\Email\ValidadorDeServidorSmtp(fn () => ['142.250.1.1']));
    }

    private function dados(array $extra = []): array
    {
        return $extra + [
            'servidor' => 'smtp.exemplo.com',
            'porta' => 587,
            'encriptacao' => 1,
            'utilizador' => 'conta',
            'senha' => 'segredo-limpo-123',
            'validar_certificado' => true,
            'dominio_ehlo' => null,
            'tempo_limite' => 15,
            'remetente_nome' => 'Secretaria',
            'remetente_endereco' => 'secretaria@exemplo.com',
            'responder_para' => null,
        ];
    }

    private function guardar(array $dados): ConfiguracaoEmail
    {
        return app(GuardarConfiguracaoEmailAction::class)->executar($dados);
    }

    private function testarCom(?string $falha, ConfiguracaoEmail $c, array &$enviados = []): ConfiguracaoEmail
    {
        $action = new TestarConfiguracaoEmailAction(function (array $config, ConfiguracaoEmail $c, string $destino) use ($falha, &$enviados) {
            $enviados[] = [$config, $destino];

            if ($falha !== null) {
                throw new RuntimeException($falha);
            }
        });

        return $action->executar($c, 'admin@exemplo.com');
    }

    public function test_guardar_nova_configuracao_fica_por_verificar_e_inactiva(): void
    {
        $c = $this->guardar($this->dados());

        $this->assertSame(EstadoConfiguracaoEmail::POR_VERIFICAR, $c->estado);
        $this->assertFalse($c->activo);
        $this->assertSame('segredo-limpo-123', $c->senha);
        $this->assertSame(1, ConfiguracaoEmail::count());
    }

    public function test_guardar_sem_senha_mantem_a_antiga(): void
    {
        $this->guardar($this->dados());

        foreach ([null, ''] as $vazia) {
            $c = $this->guardar($this->dados(['senha' => $vazia, 'servidor' => 'smtp2.exemplo.com']));
            $this->assertSame('segredo-limpo-123', $c->fresh()->senha);
        }

        $this->assertSame(1, ConfiguracaoEmail::count());
    }

    public function test_editar_so_o_nome_do_remetente_volta_a_por_verificar_e_desliga(): void
    {
        $c = $this->guardar($this->dados());
        $c->forceFill(['estado' => EstadoConfiguracaoEmail::VERIFICADO, 'activo' => true, 'ultimo_erro' => 'x'])->save();

        $c = $this->guardar($this->dados(['senha' => '', 'remetente_nome' => 'Outro nome']));

        $this->assertSame(EstadoConfiguracaoEmail::POR_VERIFICAR, $c->estado);
        $this->assertFalse($c->activo);
        $this->assertNull($c->ultimo_erro);
        $this->assertSame('segredo-limpo-123', $c->fresh()->senha);
    }

    public function test_guardar_sem_alterar_nada_mantem_o_estado_verificado(): void
    {
        $c = $this->guardar($this->dados());
        $c->forceFill(['estado' => EstadoConfiguracaoEmail::VERIFICADO, 'activo' => true])->save();

        $c = $this->guardar($this->dados(['senha' => '']));

        $this->assertSame(EstadoConfiguracaoEmail::VERIFICADO, $c->estado);
        $this->assertTrue($c->activo);
    }

    public function test_porta_e_servidor_invalidos_sao_recusados(): void
    {
        try {
            $this->guardar($this->dados(['porta' => 80]));
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('porta', $e->errors());
        }

        $this->app->bind(\Modules\Core\Comunicacao\Email\ValidadorDeServidorSmtp::class, fn () => new \Modules\Core\Comunicacao\Email\ValidadorDeServidorSmtp);
        try {
            $this->guardar($this->dados(['servidor' => '127.0.0.1']));
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('servidor', $e->errors());
        }

        $this->assertSame(0, ConfiguracaoEmail::count());
    }

    public function test_testar_com_sucesso_activa(): void
    {
        $c = $this->guardar($this->dados());
        $enviados = [];

        $c = $this->testarCom(null, $c, $enviados);

        $this->assertSame(EstadoConfiguracaoEmail::VERIFICADO, $c->estado);
        $this->assertTrue($c->activo);
        $this->assertNotNull($c->verificada_em);
        $this->assertNull($c->ultimo_erro);
        $this->assertSame('smtp.exemplo.com', $enviados[0][0]['host']);
        $this->assertSame('admin@exemplo.com', $enviados[0][1]);
    }

    public function test_testar_com_falha_marca_com_falha_e_o_erro_nao_leva_a_senha(): void
    {
        $c = $this->guardar($this->dados());

        $c = $this->testarCom('Falha de autenticação para conta com segredo-limpo-123: '.str_repeat('x', 400), $c);

        $this->assertSame(EstadoConfiguracaoEmail::COM_FALHA, $c->estado);
        $this->assertFalse($c->activo);
        $this->assertNotNull($c->ultimo_erro);
        $this->assertStringNotContainsString('segredo-limpo-123', $c->ultimo_erro);
        $this->assertStringNotContainsString('conta', $c->ultimo_erro);
        $this->assertLessThanOrEqual(200, mb_strlen($c->ultimo_erro));
    }

    public function test_testar_recusa_servidor_interno_sem_enviar(): void
    {
        $c = $this->guardar($this->dados());
        $c->forceFill(['servidor' => '10.0.0.5'])->save();
        $this->app->bind(\Modules\Core\Comunicacao\Email\ValidadorDeServidorSmtp::class, fn () => new \Modules\Core\Comunicacao\Email\ValidadorDeServidorSmtp);
        $enviados = [];

        $c = $this->testarCom(null, $c, $enviados);

        $this->assertSame([], $enviados);
        $this->assertSame(EstadoConfiguracaoEmail::COM_FALHA, $c->estado);
    }

    public function test_desactivar_desliga_e_audita(): void
    {
        $c = $this->guardar($this->dados());
        $c = $this->testarCom(null, $c);

        app(DesactivarConfiguracaoEmailAction::class)->executar($c);

        $this->assertFalse($c->fresh()->activo);
        $this->assertTrue(AuditoriaTenant::where('accao', 'configuracao_email.desactivada')->exists());
    }

    public function test_a_auditoria_nunca_contem_a_senha(): void
    {
        $c = $this->guardar($this->dados());
        $this->testarCom('falhou com segredo-limpo-123', $c);
        $this->guardar($this->dados(['senha' => 'outra-senha-456', 'servidor' => 'smtp9.exemplo.com']));

        $accoes = AuditoriaTenant::pluck('accao')->all();
        $this->assertContains('configuracao_email.guardada', $accoes);
        $this->assertContains('configuracao_email.testada', $accoes);

        $tudo = json_encode(AuditoriaTenant::all()->toArray());
        $this->assertStringNotContainsString('segredo-limpo-123', $tudo);
        $this->assertStringNotContainsString('outra-senha-456', $tudo);
        $this->assertStringContainsString('senha_alterada', $tudo);
    }

    public function test_se_a_configuracao_mudar_durante_o_teste_nao_activa_nada(): void
    {
        $c = $this->guardar($this->dados());

        $action = new TestarConfiguracaoEmailAction(function () {
            // Outro administrador guarda um servidor novo enquanto o teste do antigo ainda corre.
            $this->guardar($this->dados(['senha' => '', 'servidor' => 'smtp-novo.exemplo.com']));
        });

        try {
            $action->executar($c, 'admin@exemplo.com');
            $this->fail('O teste devia ter sido recusado.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('geral', $e->errors());
        }

        $actual = ConfiguracaoEmail::sole();
        $this->assertSame('smtp-novo.exemplo.com', $actual->servidor);
        $this->assertSame(EstadoConfiguracaoEmail::POR_VERIFICAR, $actual->estado);
        $this->assertFalse($actual->activo);
    }

    public function test_bloqueada_pela_plataforma_recusa_testar_e_nao_envia(): void
    {
        $c = $this->guardar($this->dados());
        ConfiguracaoEmail::query()->update(['bloqueada_pela_plataforma' => true]);
        $enviados = [];

        try {
            $this->testarCom(null, $c->fresh(), $enviados);
            $this->fail('O teste devia ter sido recusado.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('geral', $e->errors());
        }

        $this->assertSame([], $enviados);
        $this->assertFalse(ConfiguracaoEmail::sole()->activo);
    }

    public function test_guardar_nao_levanta_o_bloqueio_da_plataforma(): void
    {
        $this->guardar($this->dados());
        ConfiguracaoEmail::query()->update(['bloqueada_pela_plataforma' => true]);

        $c = $this->guardar($this->dados(['senha' => '', 'remetente_nome' => 'Outro']));

        $this->assertTrue($c->fresh()->bloqueada_pela_plataforma);
    }
}
