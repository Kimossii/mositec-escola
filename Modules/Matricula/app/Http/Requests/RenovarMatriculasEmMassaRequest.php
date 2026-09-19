<?php

namespace Modules\Matricula\Http\Requests;

use App\Http\Requests\BaseRequest;

class RenovarMatriculasEmMassaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('matricula.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'matricula_ids' => ['required', 'array', 'min:1'],
            'matricula_ids.*' => ['integer', 'exists:matriculas,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'matricula_ids.required' => 'Selecciona pelo menos uma matrícula.',
            'matricula_ids.min' => 'Selecciona pelo menos uma matrícula.',
        ];
    }
}
