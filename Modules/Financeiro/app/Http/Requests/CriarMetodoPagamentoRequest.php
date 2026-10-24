<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Modules\Core\Tenancy\TenantContext;
use Modules\Financeiro\Enums\TipoMetodoPagamento;
use Modules\Financeiro\Http\Requests\Concerns\ValidaDadosMetodoPagamento;

class CriarMetodoPagamentoRequest extends BaseRequest
{
    use ValidaDadosMetodoPagamento;

    public function authorize(): bool
    {
        return $this->user()?->can('metodo-pagamento.criar') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizarDadosMetodoPagamento();
    }

    public function rules(): array
    {
        return array_merge([
            'nome' => [
                'required',
                'string',
                'max:100',
                Rule::unique('metodos_pagamento', 'nome')
                    ->where(fn ($query) => $query->where('tenant_id', app(TenantContext::class)->id())),
            ],
            'tipo' => ['required', new Enum(TipoMetodoPagamento::class)],
            'exige_comprovativo' => ['boolean'],
        ], $this->regrasDosDadosMetodoPagamento());
    }

    public function messages(): array
    {
        return array_merge([
            'nome.required' => 'O nome do método de pagamento é obrigatório.',
            'nome.unique' => 'Já existe um método de pagamento com este nome.',
            'nome.max' => 'O nome do método de pagamento não pode ultrapassar 100 caracteres.',
            'tipo.required' => 'O tipo do método de pagamento é obrigatório.',
            'exige_comprovativo.boolean' => 'O valor de «exige comprovativo» é inválido.',
        ], $this->mensagensDosDadosMetodoPagamento());
    }
}
