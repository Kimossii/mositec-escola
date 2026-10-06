<?php

namespace Modules\Core\Tenancy;

use DateInterval;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Invólucro fino sobre a cache: todas as chaves levam o prefixo do tenant corrente.
 * Sem contexto lança TenantNaoResolvido. É o único caminho permitido para a cache nos módulos.
 */
class CacheTenant
{
    public function __construct(private TenantContext $contexto) {}

    public function chave(string $chave): string
    {
        return "tenant:{$this->contexto->id()}:{$chave}";
    }

    public function get(string $chave, mixed $padrao = null): mixed
    {
        return Cache::get($this->chave($chave), $padrao);
    }

    public function put(string $chave, mixed $valor, DateTimeInterface|DateInterval|int|null $ttl = null): bool
    {
        return Cache::put($this->chave($chave), $valor, $ttl);
    }

    public function forever(string $chave, mixed $valor): bool
    {
        return Cache::forever($this->chave($chave), $valor);
    }

    public function forget(string $chave): bool
    {
        return Cache::forget($this->chave($chave));
    }
}
