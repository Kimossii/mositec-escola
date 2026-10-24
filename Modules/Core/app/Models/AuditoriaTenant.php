<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Modules\Core\Tenancy\PertenceAoTenant;

/** Auditoria da escola: só se acrescenta (nunca se edita nem elimina). */
class AuditoriaTenant extends Model
{
    use PertenceAoTenant;

    public $timestamps = false;

    protected $table = 'auditoria_tenant';

    protected $fillable = ['utilizador_id', 'accao', 'entidade_tipo', 'entidade_id', 'detalhe', 'ip'];

    protected $casts = ['detalhe' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A auditoria só se acrescenta.'));
        static::deleting(fn () => throw new LogicException('A auditoria só se acrescenta.'));
    }
}
