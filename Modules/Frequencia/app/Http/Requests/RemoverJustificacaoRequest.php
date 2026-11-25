<?php

namespace Modules\Frequencia\Http\Requests;

use App\Http\Requests\BaseRequest;

class RemoverJustificacaoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('frequencia.justificar') ?? false;
    }

    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'motivo.required' => 'Indique o motivo de retirar a justificação.',
            'motivo.max' => 'O motivo não pode ter mais de 500 caracteres.',
        ];
    }
}
