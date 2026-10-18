<?php

namespace Modules\Financeiro\Services;

use Illuminate\Support\Facades\Log;
use Modules\Core\Tenancy\CacheTenant;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\Models\RegraCobranca;
use Throwable;

/**
 * Multas por atraso sem depender do agendador: na primeira visita de cada dia a um tenant aplica as multas devidas
 * (o mesmo `AplicarMultasService::executar()` do comando das 02:30, idempotente e calculado pelas datas-limite,
 * por isso o resultado é igual venha de onde vier). O agendador fica como segurança extra.
 */
class AplicarMultasDiariasService
{
    public function __construct(
        private AplicarMultasService $multas,
        private CacheTenant $cache,
        private RelogioDoTenant $relogio,
    ) {
    }

    public function executarSeNecessario(): void
    {
        $chave = $this->chaveDoDia();

        // Caminho comum (já feito hoje): uma leitura de cache. O `add` atómico garante uma só execução por dia
        // mesmo com vários primeiros acessos em simultâneo.
        if ($this->cache->get($chave) !== null || ! $this->cache->add($chave, true, now()->addDay())) {
            return;
        }

        // Leitura pura: RegraCobranca::doTenant() faria firstOrCreate num pedido GET.
        if (! (RegraCobranca::query()->first()?->multa_activa ?? false)) {
            return; // desligado: o dia fica marcado; ligar ou editar as regras volta a permitir (esquecerDoDia)
        }

        try {
            $resultado = $this->multas->executar();
            Log::info('financeiro.multas-do-dia', $resultado);
        } catch (Throwable $e) {
            $this->cache->forget($chave); // tenta de novo na próxima visita; a página não deve falhar por isto
            Log::error('financeiro.multas-do-dia: falhou', ['erro' => $e->getMessage()]);
        }
    }

    /** Depois de mudar as regras de cobrança: o varrimento de hoje volta a poder correr. */
    public function esquecerDoDia(): void
    {
        $this->cache->forget($this->chaveDoDia());
    }

    private function chaveDoDia(): string
    {
        return 'financeiro:multas-do-dia:' . $this->relogio->hoje()->toDateString();
    }
}
