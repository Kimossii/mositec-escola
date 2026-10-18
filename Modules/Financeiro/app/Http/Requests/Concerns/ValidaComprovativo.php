<?php

namespace Modules\Financeiro\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Modules\Financeiro\Models\MetodoPagamento;

/**
 * Validação do comprovativo de pagamento (PDF, JPG, JPEG, PNG ou WEBP, até 5 MB). Obrigatório só quando o método
 * escolhido tem `exige_comprovativo`; caso contrário é opcional. A regra é aplicada apenas no registo do pagamento
 * (multipart), nunca na pré-visualização.
 */
trait ValidaComprovativo
{
    /** @return array<string, mixed> */
    protected function regrasDoComprovativo(): array
    {
        return [
            'comprovativo' => [
                Rule::requiredIf($this->metodoExigeComprovativo()),
                'file',
                'mimes:pdf,jpg,jpeg,png,webp',
                'max:5120',
            ],
        ];
    }

    /** @return array<string, string> */
    protected function mensagensDoComprovativo(): array
    {
        return [
            'comprovativo.required' => 'Anexe o comprovativo de pagamento.',
            'comprovativo.file' => 'O comprovativo tem de ser um ficheiro.',
            'comprovativo.mimes' => 'O comprovativo tem de ser um PDF, JPG, JPEG, PNG ou WEBP.',
            'comprovativo.max' => 'O comprovativo não pode exceder 5 MB.',
        ];
    }

    private function metodoExigeComprovativo(): bool
    {
        $id = $this->input('metodo_pagamento_id');

        if (! is_numeric($id)) {
            return false;
        }

        return (bool) MetodoPagamento::query()->whereKey((int) $id)->value('exige_comprovativo');
    }
}
