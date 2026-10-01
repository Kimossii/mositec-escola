<?php

namespace Modules\Autenticacao\Passwords;

use Illuminate\Auth\Passwords\DatabaseTokenRepository;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Modules\Core\Tenancy\TenantContext;

/**
 * Repositório de tokens de recuperação de palavra-passe isolado por tenant (spec §10.4).
 * Toda a consulta do repositório do Laravel passa por getTable(), e toda a escrita por
 * getPayload(): filtrar e gravar o tenant nesses dois pontos cobre todos os métodos.
 */
class TokenRepositoryTenant extends DatabaseTokenRepository
{
    protected function getTable(): Builder
    {
        return parent::getTable()->where('tenant_id', app(TenantContext::class)->id());
    }

    protected function getPayload($email, #[\SensitiveParameter] $token): array
    {
        return [
            'tenant_id' => app(TenantContext::class)->id(),
            'email' => $email,
            'token' => $this->hasher->make($token),
            'created_at' => new Carbon(),
        ];
    }
}
