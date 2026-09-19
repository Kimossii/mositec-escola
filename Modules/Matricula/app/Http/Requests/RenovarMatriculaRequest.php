<?php

namespace Modules\Matricula\Http\Requests;

use App\Http\Requests\BaseRequest;

class RenovarMatriculaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('matricula.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'turma_id' => ['nullable', 'integer', 'exists:turmas,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'turma_id.exists' => 'A turma seleccionada não existe.',
        ];
    }
}
