<?php

namespace Modules\Financeiro\Services;

use Modules\Core\Tenancy\CacheTenant;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Core\Traits\LogService;
use Modules\Financeiro\Jobs\AplicarMultasDoTenantJob;
use Modules\Financeiro\Models\RegraCobranca;
use Throwable;

/**
 * Multas por atraso sem depender do agendador: na primeira visita de cada dia a um tenant aplica as multas devidas
 * (o mesmo `AplicarMultasService::executar()` do comando das 02:30, idempotente e calculado pelas datas-limite,
 * por isso o resultado é igual venha de onde vier). O agendador fica como segurança extra.
 *
 * O cálculo vai num job. Com fila a sério (database, redis...) o pedido só despacha e segue, sem esperar; sem fila
 * (driver `sync`) o job corre já dentro do pedido, como sempre foi. Se falhar, espera-se `PAUSA_APOS_FALHA_MINUTOS`
 * antes de tentar de novo, em vez de repetir em cada pedido de cada utilizador.
 */
class AplicarMultasDiariasService
{
    use LogService;

    private const PAUSA_APOS_FALHA_MINUTOS = 15;

    public function __construct(
        private CacheTenant $cache,
        private RelogioDoTenant $relogio,
    ) {
    }

    public function executarSeNecessario(): void
    {
        $chave = $this->chaveDoDia();

        if ($this->cache->get($this->chaveDaPausa()) !== null) {
            return; // falhou há pouco: não insistir em cada pedido
        }

        // Caminho comum (já feito hoje): uma leitura de cache. O `add` atómico garante uma só execução por dia
        // mesmo com vários primeiros acessos em simultâneo.
        if ($this->cache->get($chave) !== null || ! $this->cache->add($chave, true, $this->relogio->agora()->addDay())) {
            return;
        }

        // Leitura pura: RegraCobranca::doTenant() faria firstOrCreate num pedido GET.
        if (! (RegraCobranca::query()->first()?->multa_activa ?? false)) {
            return; // desligado: o dia fica marcado; ligar ou editar as regras volta a permitir (esquecerDoDia)
        }

        try {
            AplicarMultasDoTenantJob::dispatch();
        } catch (Throwable $e) {
            // Só chega aqui sem fila (sync) ou se não conseguiu despachar. Volta a tentar depois da pausa; a página não falha por isto.
            $this->cache->forget($chave);
            $this->cache->put($this->chaveDaPausa(), true, $this->relogio->agora()->addMinutes(self::PAUSA_APOS_FALHA_MINUTOS));
            $this->logError('financeiro.multas-do-dia: falhou', ['erro' => $e->getMessage()]);
        }
    }

    /** Depois de mudar as regras de cobrança: o varrimento de hoje volta a poder correr. */
    public function esquecerDoDia(): void
    {
        $this->cache->forget($this->chaveDoDia());
    }

    private function chaveDaPausa(): string
    {
        return 'financeiro:multas-do-dia:pausa';
    }

    private function chaveDoDia(): string
    {
        return 'financeiro:multas-do-dia:' . $this->relogio->hoje()->toDateString();
    }
}
