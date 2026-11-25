<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Usuario\Models\User;

/** Auditoria da escola: só se acrescenta (nunca se edita nem elimina). */
class AuditoriaTenant extends Model
{
    use PertenceAoTenant;

    public $timestamps = false;

    protected $table = 'auditoria_tenant';

    protected $fillable = ['utilizador_id', 'accao', 'entidade_tipo', 'entidade_id', 'detalhe', 'ip'];

    protected $casts = ['detalhe' => 'array', 'created_at' => 'datetime'];

    public function utilizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilizador_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A auditoria só se acrescenta.'));
        static::deleting(fn () => throw new LogicException('A auditoria só se acrescenta.'));
    }
}
