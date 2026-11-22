<?php

namespace Modules\Licenciamento\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Histórico append-only: só se acrescenta. Editar ou apagar lança. O `detalhe` nunca leva o token nem chaves. */
class EventoDeLicenca extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'licenciamento_eventos';

    protected $guarded = [];

    protected $casts = [
        'super_admin_id' => 'integer',
        'detalhe' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('O histórico de licenças é só de acréscimo: não se edita.'));
        static::deleting(fn () => throw new LogicException('O histórico de licenças é só de acréscimo: não se apaga.'));
    }
}
