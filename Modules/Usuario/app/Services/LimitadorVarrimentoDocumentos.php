<?php

namespace Modules\Usuario\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Modules\Core\Tenancy\CacheTenant;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\User;

/** Trava o varrimento de IDs: um utilizador só pode abrir documentos de MAX_PESSOAS pessoas distintas por JANELA_SEGUNDOS. */
class LimitadorVarrimentoDocumentos
{
    public function __construct(private CacheTenant $cache)
    {
    }

    public const MAX_PESSOAS = 10;

    public const JANELA_SEGUNDOS = 600;

    /** @throws ThrottleRequestsException quando a pessoa seria a (MAX_PESSOAS + 1)-ésima distinta na janela. */
    public function registar(User $utilizador, DadosPessoa $alvo): void
    {
        if ($utilizador->dados_pessoa_id !== null && (int) $utilizador->dados_pessoa_id === (int) $alvo->id) {
            return;
        }

        $chave = 'docs-varrimento:'.$utilizador->id;

        // Ler-filtrar-gravar sob trinco: pedidos em paralelo não podem passar todos pelo mesmo estado antigo.
        try {
            $this->cache->lock($chave.':trinco', 5)->block(3, fn () => $this->contar($chave, $alvo));
        } catch (LockTimeoutException) {
            throw new ThrottleRequestsException('Demasiados pedidos em simultâneo. Tente novamente.');
        }
    }

    private function contar(string $chave, DadosPessoa $alvo): void
    {
        $agora = now()->getTimestamp();
        $limite = $agora - self::JANELA_SEGUNDOS;

        $vistas = array_filter($this->cache->get($chave, []), fn (int $quando) => $quando > $limite);

        if (! isset($vistas[$alvo->id]) && count($vistas) >= self::MAX_PESSOAS) {
            throw new ThrottleRequestsException('Demasiados documentos de pessoas diferentes abertos. Tente mais tarde.');
        }

        $vistas[$alvo->id] = $agora;
        $this->cache->put($chave, $vistas, self::JANELA_SEGUNDOS);
    }
}
