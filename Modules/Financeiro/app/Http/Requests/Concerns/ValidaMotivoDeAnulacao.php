<?php

namespace Modules\Financeiro\Http\Requests\Concerns;

use Modules\Financeiro\DTO\MotivoDeAnulacaoDTO;

trait ValidaMotivoDeAnulacao
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('motivo'))) {
            $this->merge(['motivo' => trim($this->input('motivo'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['motivo' => ['required', 'string', 'min:' . MotivoDeAnulacaoDTO::MINIMO, 'max:' . MotivoDeAnulacaoDTO::MAXIMO]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'motivo.required' => 'Indique o motivo da anulação.',
            'motivo.min' => 'O motivo tem de ter pelo menos ' . MotivoDeAnulacaoDTO::MINIMO . ' caracteres.',
            'motivo.max' => 'O motivo não pode ter mais de ' . MotivoDeAnulacaoDTO::MAXIMO . ' caracteres.',
        ];
    }
}
