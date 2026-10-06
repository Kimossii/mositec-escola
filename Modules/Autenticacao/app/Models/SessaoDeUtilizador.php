<?php

namespace Modules\Autenticacao\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Linha da tabela `sessions` (driver de sessão `database`). É infra-estrutura
 * partilhada, sem tenant_id (spec §10.2): por isso não usa PertenceAoTenant e
 * só deve ser consultada por `user_id`, já resolvido dentro do tenant.
 */
class SessaoDeUtilizador extends Model
{
    protected $table = 'sessions';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];
}
