<?php

namespace Modules\Permissao\Support;

use Illuminate\Support\Facades\Cache;
use Modules\Permissao\Services\PermissionResolver;

class PermissaoCache
{
    private const CHAVE_EPOCH = 'permissoes:epoch';

    public function chave(int $userId): string
    {
        return "permissoes:v{$this->epoch()}:user:{$userId}";
    }

    public function obter(int $userId): ?array
    {
        return Cache::get($this->chave($userId));
    }

    public function guardar(int $userId, array $conjunto): void
    {
        Cache::forever($this->chave($userId), $conjunto);
    }

    public function esquecerUtilizador(int $userId): void
    {
        Cache::forget($this->chave($userId));

        // PermissionResolver é singleton: a sua memoização em memória
        // ($memoria) não é afetada pela invalidação da cache persistente
        // acima, logo precisa de ser limpa explicitamente aqui.
        app(PermissionResolver::class)->esquecerUtilizador($userId);
    }

    public function invalidarTudo(): void
    {
        Cache::forever(self::CHAVE_EPOCH, $this->epoch() + 1);

        // Ver comentário em esquecerUtilizador(): o bump de epoch invalida
        // a cache persistente, mas não a memoização em memória do
        // PermissionResolver singleton.
        app(PermissionResolver::class)->esquecerTudo();
    }

    private function epoch(): int
    {
        return (int) Cache::get(self::CHAVE_EPOCH, 1);
    }
}
