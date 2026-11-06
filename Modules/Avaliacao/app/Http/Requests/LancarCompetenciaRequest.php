<?php

namespace Modules\Avaliacao\Http\Requests;

use App\Http\Requests\BaseRequest;

class LancarCompetenciaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('nota.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'inscricao_disciplina_id' => ['required', 'integer'],
            'competencia_id' => ['required', 'integer'],
            'periodo_id' => ['required', 'integer'],
            'escala_nivel_id' => ['required', 'integer'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return MensagensDeAvaliacao::todas();
    }
}
