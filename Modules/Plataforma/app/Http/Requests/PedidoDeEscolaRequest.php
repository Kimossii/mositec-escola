<?php

namespace Modules\Plataforma\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base dos pedidos sobre uma escola já existente (`{tenant}` resolvido pelo código). Só formato:
 * as regras de negócio pertencem às Actions do módulo Tenant. Um pedido inválido volta ao detalhe
 * da escola, onde os erros se mostram.
 */
abstract class PedidoDeEscolaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function getRedirectUrl(): string
    {
        return route('plataforma.escolas.show', $this->route('tenant')->codigo);
    }

    public function messages(): array
    {
        return [
            'required' => 'Este campo é obrigatório.',
            'string' => 'Valor inválido.',
            'max' => 'Demasiado longo (máximo :max caracteres).',
        ];
    }
}
