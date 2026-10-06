<?php

namespace Modules\Plataforma\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rasto das acções da Plataforma. Só se acrescenta (sem updated_at). O código do tenant é
 * texto, sem chave estrangeira. Gravar sempre por RegistarAuditoriaAction, que recusa segredos.
 */
class RegistoDeAuditoria extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'plataforma_auditoria';

    protected $fillable = [
        'super_admin_id',
        'accao',
        'codigo_tenant',
        'detalhe',
        'ip',
    ];

    protected $casts = [
        'detalhe' => 'array',
        'created_at' => 'datetime',
    ];

    public function autor(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'super_admin_id');
    }
}
