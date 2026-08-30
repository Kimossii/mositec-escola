<?php

namespace Modules\Core\Traits;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Enums\Estado;

trait AlternaEstado
{
    protected function alternarEstado(Model $model): Model
    {
        $novoEstado = $model->estado === Estado::ATIVO->value
            ? Estado::INATIVO
            : Estado::ATIVO;

        $model->update(['estado' => $novoEstado->value]);

        return $model;
    }
}
