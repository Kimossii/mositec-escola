<?php

namespace Modules\Frequencia\Http\Requests;

use App\Http\Requests\BaseRequest;

class JustificarFaltaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('frequencia.justificar') ?? false;
    }

    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'max:500'],
            'documento_referencia' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'motivo.required' => 'Indique o motivo da justificação.',
            'motivo.max' => 'O motivo não pode ter mais de 500 caracteres.',
            'documento_referencia.max' => 'A referência do documento não pode ter mais de 255 caracteres.',
        ];
    }
}
