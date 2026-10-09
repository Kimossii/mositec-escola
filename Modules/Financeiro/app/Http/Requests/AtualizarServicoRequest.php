<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Tenancy\TenantContext;

class AtualizarServicoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalogo-financeiro.editar') ?? false;
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
                Rule::unique('servicos', 'codigo')
                    ->where(fn ($query) => $query->where('tenant_id', app(TenantContext::class)->id()))
                    ->ignore($this->route('servico')?->id),
            ],
            'preco' => ['required', 'regex:/^\d{1,12}([.,]\d{1,2})?$/D'],
        ];
    }

    public function messages(): array
    {
        return [
            'nome.required' => 'O nome do serviço é obrigatório.',
            'nome.max' => 'O nome do serviço não pode ultrapassar 255 caracteres.',
            'codigo.unique' => 'Já existe um serviço com este código.',
            'codigo.max' => 'O código do serviço não pode ultrapassar 50 caracteres.',
            'preco.required' => 'O preço do serviço é obrigatório.',
            'preco.regex' => 'O preço é inválido: use dígitos com até duas casas decimais (ex.: 25000 ou 25000,50).',
        ];
    }
}
