<?php

namespace Modules\Core\Traits;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Enums\Estado;

trait AlternaEstado
{
    protected function alternarEstado(Model $model): Model
    {
        $atual = $model->estado instanceof Estado
            ? $model->estado
            : Estado::tryFrom((int) $model->estado);

        $novoEstado = $atual === Estado::ATIVO
            ? Estado::INATIVO
            : Estado::ATIVO;

        $model->update(['estado' => $novoEstado->value]);

        return $model;
    }
}
