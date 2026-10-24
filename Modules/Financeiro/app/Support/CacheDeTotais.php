<?php

namespace Modules\Financeiro\Support;

use Closure;
use Illuminate\Support\Str;
use Modules\Core\Tenancy\CacheTenant;
use Modules\Estabelecimento\Services\RelogioDoTenant;

/**
 * Cache curto (5 min) dos totais pesados de Propinas, Dívidas e Relatórios, por escola, filtros e dia. Qualquer
 * escrita financeira muda a «versão» (traço InvalidaTotaisFinanceiros) e os totais recalculam-se logo: o cache só
 * poupa trabalho entre escritas, nunca mostra números atrasados depois de um pagamento.
 */
class CacheDeTotais
{
    public const TTL = 300;

    public function __construct(private CacheTenant $cache, private RelogioDoTenant $relogio)
    {
    }

    public function lembrar(string $nome, array $filtros, Closure $calcular): mixed
    {
        $chave = sprintf('totais:%s:%s:%s:%s', $this->cache->get('totais:versao', '0'), $this->relogio->hoje()->toDateString(), $nome, md5(json_encode($filtros)));
        $guardado = $this->cache->get($chave);

        if ($guardado !== null) {
            return $guardado;
        }

        $valor = $calcular();
        $this->cache->put($chave, $valor, self::TTL);

        return $valor;
    }

    public function invalidar(): void
    {
        $this->cache->forever('totais:versao', (string) Str::uuid());
    }
}
