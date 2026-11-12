<?php

namespace Modules\Avaliacao\Http\Requests;

use App\Http\Requests\BaseRequest;

class AnularNotaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('nota.anular') ?? false;
    }

    public function rules(): array
    {
        return [
            'conta_como_zero' => ['required', 'boolean'],
            'motivo' => ['required', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return MensagensDeAvaliacao::todas();
    }
}
