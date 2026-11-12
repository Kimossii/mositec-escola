<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;

class TerminarBolsaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bolsa.editar') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['motivo' => ['required', 'string', 'min:5', 'max:1000']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'motivo.required' => 'Indique o motivo.',
            'motivo.min' => 'Indique o motivo (mínimo 5 caracteres).',
        ];
    }
}
