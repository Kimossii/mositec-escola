<?php

namespace Modules\Core\Traits;

use Modules\Core\Enums\Estado;

trait SincronizaEstadoDescricao
{
    protected static function bootSincronizaEstadoDescricao(): void
    {
        static::saving(function ($model) {
            $model->estado_descricao = Estado::from($model->estado ?? 1)->label();
        });
    }
}
