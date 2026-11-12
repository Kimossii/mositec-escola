<?php

namespace Modules\Avaliacao\Http\Requests;

use App\Http\Requests\BaseRequest;

class ProlongarPrazoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('nota.prolongar-prazo') ?? false;
    }

    public function rules(): array
    {
        return [
            'data_limite' => ['required', 'date_format:Y-m-d'],
            'motivo' => ['required', 'string', 'max:500'],
            'turma_id' => ['nullable', 'integer'],
            'disciplina_id' => ['nullable', 'integer'],
            'docente_id' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return MensagensDeAvaliacao::todas();
    }
}
