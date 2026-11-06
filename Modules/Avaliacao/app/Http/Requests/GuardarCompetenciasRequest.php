<?php

namespace Modules\Avaliacao\Http\Requests;

use App\Http\Requests\BaseRequest;

class GuardarCompetenciasRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('nota.configurar') ?? false;
    }

    public function rules(): array
    {
        return [
            'disciplina_id' => ['required', 'integer'],
            'nivel_academico_id' => ['nullable', 'integer'],
            'competencias' => ['present', 'array', 'max:30'],
            'competencias.*.id' => ['nullable', 'integer'],
            'competencias.*.descricao' => ['required', 'string', 'max:200'],
            'competencias.*.ativa' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return MensagensDeAvaliacao::todas();
    }
}
