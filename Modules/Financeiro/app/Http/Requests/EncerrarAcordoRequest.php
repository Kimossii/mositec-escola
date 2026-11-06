<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;

class EncerrarAcordoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('acordo.cancelar') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'destino' => ['required', Rule::in(['cancelar', 'quebrar'])],
            'motivo' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'destino.required' => 'Escolha se o acordo se cancela ou se quebra.',
            'destino.in' => 'O destino indicado é inválido.',
            'motivo.required' => 'Indique o motivo.',
            'motivo.min' => 'Indique o motivo (mínimo 5 caracteres).',
        ];
    }

    /** `quebrar`: o devedor falhou e as multas voltam a incidir; `cancelar`: a escola desiste do acordo. */
    public function quebra(): bool
    {
        return $this->validated()['destino'] === 'quebrar';
    }
}
