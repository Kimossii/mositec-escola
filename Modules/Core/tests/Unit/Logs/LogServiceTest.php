<?php

namespace Modules\Core\Tests\Unit\Logs;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Modules\Core\Support\SanitizadorDeLogs;
use Modules\Core\Traits\LogService;
use RuntimeException;
use Tests\TestCase;

class LogServiceTest extends TestCase
{
    use RefreshDatabase;

    private function emissor(): object
    {
        return new class
        {
            use LogService;

            public function registar(string $mensagem, array $contexto = []): void
            {
                $this->logInfo($mensagem, $contexto);
            }

            public function falhar(): RuntimeException
            {
                return $this->logException(new RuntimeException('boom'), 'Falhou');
            }

            public function operar(bool $falha, \Illuminate\Database\Eloquent\Model $modelo, string $senha, int $n = 3): string
            {
                return $this->comLog('Operar', $this->contextoDeArgumentos(func_get_args(), 'operar'), function () use ($falha) {
                    return $falha ? throw new RuntimeException('falhou mesmo') : 'ok';
                });
            }

            public function falhaJaRegistada(\Throwable $e): void
            {
                $this->logException($e, 'camada de cima');
            }

            public function debug(): void
            {
                $this->logDebug('detalhe');
            }
        };
    }

    public function test_a_mensagem_leva_classe_metodo_e_linha_de_quem_chamou(): void
    {
        Log::spy();
        $this->emissor()->registar('Pagamento registado', ['id' => 7]);

        Log::shouldHaveReceived('info')->once()->withArgs(fn (string $msg, array $ctx) => str_contains($msg, '::registar:')
            && str_contains($msg, 'Pagamento registado') && $ctx['id'] === 7 && $ctx['tenant_id'] === $this->tenant->id);
    }

    public function test_segredos_sao_escondidos_e_o_contexto_e_limitado(): void
    {
        Log::spy();
        $this->emissor()->registar('Login', [
            'password' => 'abc', 'dados' => ['api_key' => 'k', 'Authorization' => 'Bearer x', 'nome' => 'Ana'],
            'grande' => str_repeat('x', 5000), 'muitos' => range(1, 200),
        ]);

        Log::shouldHaveReceived('info')->once()->withArgs(function (string $msg, array $ctx) {
            return $ctx['password'] === '[ESCONDIDO]'
                && $ctx['dados']['api_key'] === '[ESCONDIDO]' && $ctx['dados']['Authorization'] === '[ESCONDIDO]' && $ctx['dados']['nome'] === 'Ana'
                && mb_strlen($ctx['grande']) <= SanitizadorDeLogs::TEXTO_MAXIMO + 1
                && count($ctx['muitos']) <= SanitizadorDeLogs::ITENS_MAXIMOS + 1;
        });
    }

    public function test_mensagem_numa_so_linha_sem_forjar_entradas(): void
    {
        Log::spy();
        $this->emissor()->registar("linha1\nINFO falso\r\x00fim");

        Log::shouldHaveReceived('info')->once()->withArgs(fn (string $msg) => ! preg_match('/[\r\n\x00]/', $msg));
    }

    public function test_excecao_vai_resumida_e_e_devolvida(): void
    {
        Log::spy();
        $excecao = $this->emissor()->falhar();

        $this->assertInstanceOf(RuntimeException::class, $excecao);
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $msg, array $ctx) => $ctx['excecao']['excecao'] === RuntimeException::class
            && $ctx['excecao']['mensagem'] === 'boom' && ! isset($ctx['excecao']['trace']));
    }

    public function test_debug_so_escreve_em_modo_debug(): void
    {
        Log::spy();
        config(['app.debug' => false]);
        $this->emissor()->debug();
        Log::shouldNotHaveReceived('debug');

        config(['app.debug' => true]);
        $this->emissor()->debug();
        Log::shouldHaveReceived('debug')->once();
    }

    public function test_um_canal_de_log_avariado_nunca_derruba_a_operacao(): void
    {
        Log::shouldReceive('info')->andThrow(new RuntimeException('disco cheio'));

        $this->emissor()->registar('qualquer coisa');

        $this->assertTrue(true); // chegou aqui sem excepção
    }

    public function test_com_log_regista_conclusao_com_contexto_seguro_e_aponta_para_quem_chamou(): void
    {
        Log::spy();
        $modelo = new \Modules\Usuario\Models\User;
        $modelo->id = 9;

        $this->assertSame('ok', $this->emissor()->operar(false, $modelo, 'segredo', 5));

        Log::shouldHaveReceived('info')->once()->withArgs(fn (string $msg, array $ctx) => str_contains($msg, '::operar:') && str_contains($msg, 'Operar: concluída')
            && $ctx['modelo_id'] === 9 && $ctx['n'] === 5 && $ctx['falha'] === false && ! array_key_exists('senha', $ctx));
    }

    public function test_com_log_regista_a_falha_e_relanca_a_mesma_excepcao_sem_duplicar(): void
    {
        Log::spy();
        $emissor = $this->emissor();

        try {
            $emissor->operar(true, new \Modules\Usuario\Models\User, 'x');
            $this->fail('devia relançar');
        } catch (RuntimeException $e) {
            $this->assertSame('falhou mesmo', $e->getMessage());
            // Uma camada acima volta a registar a mesma excepção: não duplica.
            $emissor->falhaJaRegistada($e);
        }

        Log::shouldHaveReceived('error')->once();
    }

    public function test_excepcoes_esperadas_entram_como_warning(): void
    {
        Log::spy();
        $emissor = $this->emissor();
        $emissor->falhaJaRegistada(\Illuminate\Validation\ValidationException::withMessages(['a' => 'x']));

        Log::shouldHaveReceived('warning')->once();
        Log::shouldNotHaveReceived('error');
    }
}
