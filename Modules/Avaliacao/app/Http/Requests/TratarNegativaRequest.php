<?php

namespace Modules\Avaliacao\Http\Requests;

use App\Http\Requests\BaseRequest;

class TratarNegativaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('nota.tratar-negativa') ?? false;
    }

    public function rules(): array
    {
        return [
            'inscricao_disciplina_id' => ['required', 'integer'],
            'medida' => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return MensagensDeAvaliacao::todas();
    }
}
