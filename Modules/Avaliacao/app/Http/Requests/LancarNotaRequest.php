<?php

namespace Modules\Avaliacao\Http\Requests;

use App\Http\Requests\BaseRequest;

class LancarNotaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('nota.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'instrumento_id' => ['required', 'integer'],
            'inscricao_disciplina_id' => ['required', 'integer'],
            'valor' => ['required', 'string', 'max:6'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return MensagensDeAvaliacao::todas();
    }
}
