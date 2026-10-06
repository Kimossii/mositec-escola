<?php

namespace Modules\Autenticacao\Models;

use Laravel\Sanctum\PersonalAccessToken;
use Modules\Core\Tenancy\PertenceAoTenant;

/**
 * Token de API de um utilizador. Com a trait, um token emitido num tenant
 * não é encontrado no domínio de outro (spec §10.3).
 */
class TokenDeAcesso extends PersonalAccessToken
{
    use PertenceAoTenant;

    /** O Sanctum deriva a tabela do nome da classe; aqui fica explícita. */
    protected $table = 'personal_access_tokens';
}
