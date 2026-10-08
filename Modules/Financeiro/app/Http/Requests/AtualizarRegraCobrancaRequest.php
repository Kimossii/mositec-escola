<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;

class AtualizarRegraCobrancaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('regra-cobranca.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'dia_vencimento' => 'required|integer|between:1,28',
            'dias_tolerancia' => 'required|integer|between:0,90',
            'permite_pagamento_parcial' => 'required|boolean',
            'permite_pagamento_antecipado' => 'required|boolean',
            'gerar_automaticamente' => 'required|boolean',
            'permite_negociacao' => 'required|boolean',
            'desconto_maximo_negociacao' => 'required|integer|between:0,100',
        ];
    }

    public function messages(): array
    {
        return [
            'dia_vencimento.between' => 'O dia de vencimento tem de estar entre 1 e 28.',
            'dias_tolerancia.between' => 'Os dias de tolerância têm de estar entre 0 e 90.',
            'desconto_maximo_negociacao.between' => 'O desconto máximo tem de estar entre 0% e 100%.',
        ];
    }
}
