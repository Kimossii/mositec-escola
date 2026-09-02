<?php

namespace Modules\Core\Traits;

trait RegistaAutoria
{
    protected static function bootRegistaAutoria(): void
    {
        static::creating(function ($model) {
            $model->criado_por = $model->criado_por ?? auth()->id();
            $model->editado_por = $model->editado_por ?? auth()->id();
        });

        static::updating(function ($model) {
            $model->editado_por = auth()->id();
        });
    }
}
