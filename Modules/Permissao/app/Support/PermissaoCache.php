<?php

namespace Modules\Permissao\Support;

use Modules\Core\Tenancy\CacheTenant;
use Modules\Permissao\Services\PermissionResolver;

class PermissaoCache
{
    private const CHAVE_EPOCH = 'permissoes:epoch';

    public function __construct(private readonly CacheTenant $cache) {}

    public function chave(int $userId): string
    {
        return "permissoes:v{$this->epoch()}:user:{$userId}";
    }

    public function obter(int $userId): ?array
    {
        return $this->cache->get($this->chave($userId));
    }

    public function guardar(int $userId, array $conjunto): void
    {
        $this->cache->forever($this->chave($userId), $conjunto);
    }

    public function esquecerUtilizador(int $userId): void
    {
        $this->cache->forget($this->chave($userId));

        // PermissionResolver é scoped (uma instância por pedido/tenant): a sua memoização em memória
        // ($memoria) não é afetada pela invalidação da cache persistente
        // acima, logo precisa de ser limpa explicitamente aqui.
        app(PermissionResolver::class)->esquecerUtilizador($userId);
    }

    public function invalidarTudo(): void
    {
        $this->cache->forever(self::CHAVE_EPOCH, $this->epoch() + 1);

        // Ver comentário em esquecerUtilizador(): o bump de epoch invalida
        // a cache persistente, mas não a memoização em memória do
        // PermissionResolver scoped.
        app(PermissionResolver::class)->esquecerTudo();
    }

    private function epoch(): int
    {
        return (int) $this->cache->get(self::CHAVE_EPOCH, 1);
    }
}
