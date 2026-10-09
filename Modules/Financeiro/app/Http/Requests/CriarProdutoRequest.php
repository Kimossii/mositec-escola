<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Tenancy\TenantContext;

class CriarProdutoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalogo-financeiro.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'descricao' => ['nullable', 'string'],
            'codigo' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('produtos', 'codigo')
                    ->where(fn ($query) => $query->where('tenant_id', app(TenantContext::class)->id())),
            ],
            'preco' => ['required', 'regex:/^\d{1,12}([.,]\d{1,2})?$/D'],
        ];
    }

    public function messages(): array
    {
        return [
            'nome.required' => 'O nome do produto é obrigatório.',
            'nome.max' => 'O nome do produto não pode ultrapassar 255 caracteres.',
            'codigo.unique' => 'Já existe um produto com este código.',
            'codigo.max' => 'O código do produto não pode ultrapassar 50 caracteres.',
            'preco.required' => 'O preço do produto é obrigatório.',
            'preco.regex' => 'O preço é inválido: use dígitos com até duas casas decimais (ex.: 25000 ou 25000,50).',
        ];
    }
}
