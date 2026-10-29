<?php

namespace Modules\Usuario\Http\Requests\Concerns;

use Modules\Usuario\Models\DadosPessoa;

/**
 * Normaliza o número de identificação (BI) antes da validação, para a regra `unique` comparar o mesmo valor que
 * fica gravado: sem espaços e em maiúsculas (`DadosPessoa::normalizarNumeroIdentificacao`).
 */
trait NormalizaNumeroIdentificacao
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('numero_identificacao'))) {
            $this->merge(['numero_identificacao' => DadosPessoa::normalizarNumeroIdentificacao($this->input('numero_identificacao'))]);
        }
    }
}
