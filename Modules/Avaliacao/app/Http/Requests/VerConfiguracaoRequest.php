<?php

namespace Modules\Avaliacao\Http\Requests;

use App\Http\Requests\BaseRequest;

class VerConfiguracaoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('nota.configurar') ?? false;
    }

    public function rules(): array
    {
        return [
            'ano' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return MensagensDeAvaliacao::todas();
    }
}
