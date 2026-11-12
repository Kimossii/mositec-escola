<?php

namespace Modules\Avaliacao\Http\Requests;

use App\Http\Requests\BaseRequest;

class VerMiniPautaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('nota.ver') ?? false;
    }

    public function rules(): array
    {
        return [
            'turma' => ['nullable', 'integer'],
            'disciplina' => ['nullable', 'integer'],
            'periodo' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return MensagensDeAvaliacao::todas();
    }
}
