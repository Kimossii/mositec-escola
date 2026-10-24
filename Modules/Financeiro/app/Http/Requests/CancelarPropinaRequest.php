<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Modules\Financeiro\Support\CancelamentoDePropinas;

class CancelarPropinaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('propina.cancelar') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('motivo'))) {
            $this->merge(['motivo' => trim($this->input('motivo'))]);
        }
    }

    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'min:' . CancelamentoDePropinas::MOTIVO_MINIMO, 'max:' . CancelamentoDePropinas::MOTIVO_MAXIMO],
        ];
    }

    public function messages(): array
    {
        return [
            'motivo.required' => 'Indique o motivo do cancelamento.',
            'motivo.min' => 'O motivo tem de ter pelo menos ' . CancelamentoDePropinas::MOTIVO_MINIMO . ' caracteres.',
            'motivo.max' => 'O motivo não pode ter mais de ' . CancelamentoDePropinas::MOTIVO_MAXIMO . ' caracteres.',
        ];
    }
}
