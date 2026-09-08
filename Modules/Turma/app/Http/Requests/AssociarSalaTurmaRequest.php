<?php

namespace Modules\Turma\Http\Requests;

use App\Http\Requests\BaseRequest;

class AssociarSalaTurmaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('turma.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'sala_id' => 'required|integer|exists:salas,id',
            'inicio' => 'required|date',
        ];
    }

    public function messages(): array
    {
        return [
            'sala_id.required' => 'A sala é obrigatória.',
            'sala_id.exists' => 'A sala indicada não existe.',
            'inicio.required' => 'A data de início é obrigatória.',
            'inicio.date' => 'A data de início deve ser uma data válida.',
        ];
    }
}
