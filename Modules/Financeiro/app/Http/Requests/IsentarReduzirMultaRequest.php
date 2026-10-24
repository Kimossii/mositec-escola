<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Modules\Financeiro\DTO\IsentarMultaDTO;
use Modules\Financeiro\Rules\ValorMonetario;

class IsentarReduzirMultaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('propina.isentar-multa') ?? false;
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
            'novo_valor' => ['required', new ValorMonetario(true)],
            'motivo' => ['required', 'string', 'min:' . IsentarMultaDTO::MOTIVO_MINIMO, 'max:' . IsentarMultaDTO::MOTIVO_MAXIMO],
        ];
    }

    public function messages(): array
    {
        return [
            'novo_valor.required' => 'Indique o novo valor da multa.',
            'motivo.required' => 'Indique o motivo da isenção ou da redução.',
            'motivo.min' => 'O motivo tem de ter pelo menos ' . IsentarMultaDTO::MOTIVO_MINIMO . ' caracteres.',
            'motivo.max' => 'O motivo não pode ter mais de ' . IsentarMultaDTO::MOTIVO_MAXIMO . ' caracteres.',
        ];
    }
}
