<?php

namespace Modules\Avaliacao\Http\Requests;

use App\Http\Requests\BaseRequest;

class LancarQualitativaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('nota.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'inscricao_disciplina_id' => ['required', 'integer'],
            'periodo_id' => ['required', 'integer'],
            'escala_nivel_id' => ['required', 'integer'],
            'observacao' => ['nullable', 'string', 'max:1000'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return MensagensDeAvaliacao::todas();
    }
}
