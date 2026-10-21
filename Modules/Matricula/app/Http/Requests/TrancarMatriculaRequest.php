<?php

namespace Modules\Matricula\Http\Requests;

use App\Http\Requests\BaseRequest;

class TrancarMatriculaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('matricula.trancar') ?? false;
    }

    public function rules(): array
    {
        return [
            'data_efeito' => ['required', 'date'],
            'motivo' => ['required', 'string', 'min:5', 'max:1000'],
            'confirmar_com_dividas' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'data_efeito.required' => 'A data é obrigatória.',
            'data_efeito.date' => 'A data é inválida.',
            'motivo.required' => 'O motivo é obrigatório.',
            'motivo.min' => 'Indique o motivo (mínimo 5 caracteres).',
        ];
    }
}
