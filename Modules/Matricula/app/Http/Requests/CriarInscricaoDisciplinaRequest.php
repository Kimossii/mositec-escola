<?php

namespace Modules\Matricula\Http\Requests;

use App\Http\Requests\BaseRequest;

class CriarInscricaoDisciplinaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('matricula.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'plano_curricular_disciplina_id' => ['required', 'integer', 'exists:plano_curricular_disciplinas,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'plano_curricular_disciplina_id.required' => 'A disciplina é obrigatória.',
            'plano_curricular_disciplina_id.exists' => 'A disciplina seleccionada não existe.',
        ];
    }
}
