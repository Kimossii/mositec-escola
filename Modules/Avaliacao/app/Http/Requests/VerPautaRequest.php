<?php

namespace Modules\Avaliacao\Http\Requests;

use App\Http\Requests\BaseRequest;

class VerPautaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('nota.ver-pauta') ?? false;
    }

    public function rules(): array
    {
        return [
            'turma' => ['nullable', 'integer'],
            'fase' => ['nullable', 'integer', 'in:1,2'],
        ];
    }

    public function messages(): array
    {
        return MensagensDeAvaliacao::todas();
    }
}
