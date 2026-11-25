<?php

namespace Modules\Frequencia\Http\Requests;

use App\Http\Requests\BaseRequest;

class NaoLeccionadaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('frequencia.editar') ?? false;
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
            'motivo.required' => 'Indique o motivo de a aula não ter sido leccionada.',
            'motivo.max' => 'O motivo não pode ter mais de 500 caracteres.',
        ];
    }
}
