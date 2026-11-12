<?php

namespace Modules\Avaliacao\Http\Requests;

use App\Http\Requests\BaseRequest;

class GuardarEscalaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('nota.configurar') ?? false;
    }

    public function rules(): array
    {
        return [
            'id' => ['nullable', 'integer'],
            'nome' => ['required', 'string', 'max:120'],
            'niveis' => ['required', 'array', 'min:2', 'max:12'],
            'niveis.*.id' => ['nullable', 'integer'],
            'niveis.*.codigo' => ['required', 'string', 'max:20'],
            'niveis.*.designacao' => ['required', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return MensagensDeAvaliacao::todas();
    }
}
