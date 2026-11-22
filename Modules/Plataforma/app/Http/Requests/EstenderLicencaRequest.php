<?php

namespace Modules\Plataforma\Http\Requests;

/** Extensão de cortesia: motivo e a nova tolerância (em dias). A Action recusa valores que não a aumentem. */
class EstenderLicencaRequest extends MotivoDeLicencaRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'tolerancia_dias' => ['required', 'integer', 'min:0', 'max:100000'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'integer' => 'Indique um número inteiro.',
            'min' => 'Valor demasiado baixo.',
            'tolerancia_dias.max' => 'Valor demasiado alto.',
        ];
    }
}
