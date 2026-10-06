<?php

namespace Modules\Turma\Http\Requests;

use App\Http\Requests\BaseRequest;

class EncerrarSalaTurmaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('turmas.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'fim' => 'required|date',
        ];
    }

    public function messages(): array
    {
        return [
            'fim.required' => 'A data de fim é obrigatória.',
            'fim.date' => 'A data de fim deve ser uma data válida.',
        ];
    }
}
