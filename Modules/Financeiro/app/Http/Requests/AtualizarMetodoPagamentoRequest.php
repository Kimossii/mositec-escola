<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Modules\Core\Tenancy\TenantContext;
use Modules\Financeiro\Enums\TipoMetodoPagamento;

class AtualizarMetodoPagamentoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('metodo-pagamento.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'nome' => [
                'required',
                'string',
                'max:100',
                Rule::unique('metodos_pagamento', 'nome')
                    ->where(fn ($query) => $query->where('tenant_id', app(TenantContext::class)->id()))
                    ->ignore($this->route('metodo')?->id),
            ],
            'tipo' => ['required', new Enum(TipoMetodoPagamento::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'nome.required' => 'O nome do método de pagamento é obrigatório.',
            'nome.unique' => 'Já existe um método de pagamento com este nome.',
            'nome.max' => 'O nome do método de pagamento não pode ultrapassar 100 caracteres.',
            'tipo.required' => 'O tipo do método de pagamento é obrigatório.',
        ];
    }
}
