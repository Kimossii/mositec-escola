<?php

namespace Modules\Licenciamento\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registo local dos direitos de um tenant. Global (sem PertenceAoTenant): quem o lê filtra por `tenant_id`.
 * O `token` é a fonte de verdade (reverificado pelo DireitosService); `claims` é só cópia informativa.
 */
class DireitosLocais extends Model
{
    protected $table = 'licenciamento_direitos';

    protected $fillable = ['licenca_id', 'emitida_em', 'token', 'claims', 'revogada_em', 'instalada_em'];

    // `tenant_id` não é preenchível em massa (regra de tenancy): atribui-se explicitamente.
    protected $hidden = ['token'];

    protected $casts = [
        'tenant_id' => 'integer',
        'emitida_em' => 'immutable_datetime',
        'claims' => 'array',
        'revogada_em' => 'immutable_datetime',
        'instalada_em' => 'immutable_datetime',
    ];
}
