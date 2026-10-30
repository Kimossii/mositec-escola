<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Exportações, descargas, importações e pesquisa global têm limite por utilizador: travam um script ou um cliente em ciclo. */
class RotasSensiveisComLimiteTest extends TestCase
{
    use RefreshDatabase;

    private const COM_LIMITE = [
        'pesquisa.global' => 'pesquisa',
        'ficheiros.descarregar' => 'operacao-pesada',
        'ficheiros.descarregar-pasta' => 'operacao-pesada',
        'ficheiros.partilhar' => 'operacao-pesada',
        'importacoes.store' => 'operacao-pesada',
        'importacoes.confirmar' => 'operacao-pesada',
        'usuario.exportar' => 'operacao-pesada',
        'documentos-pessoa.download' => 'operacao-pesada',
        'recibo.verificar' => '60,1',
    ];

    public function test_rotas_sensiveis_tem_limite_de_pedidos(): void
    {
        foreach (self::COM_LIMITE as $nome => $limite) {
            $rota = Route::getRoutes()->getByName($nome);
            $this->assertNotNull($rota, "Rota {$nome} não existe.");
            $this->assertContains("throttle:{$limite}", $rota->gatherMiddleware(), "A rota {$nome} ficou sem limite de pedidos.");
        }
    }

    public function test_os_limitadores_existem_e_cortam_ao_passar_do_limite(): void
    {
        foreach (['pesquisa' => 120, 'operacao-pesada' => 20] as $nome => $maximo) {
            $limitador = \Illuminate\Support\Facades\RateLimiter::limiter($nome);
            $this->assertNotNull($limitador, "Limitador {$nome} não definido.");
            $this->assertSame($maximo, $limitador(\Illuminate\Http\Request::create('/'))->maxAttempts);
        }
    }
}
