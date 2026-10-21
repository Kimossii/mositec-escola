<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Tenancy\TenantContext;

class CriarCobrancaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cobranca.criar') ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();

        return [
            'matricula_id' => ['required', 'integer', Rule::exists('matriculas', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at'))],
            'itens' => ['required', 'array', 'min:1', 'max:50'],
            'itens.*.tipo' => ['required', Rule::in(['produto', 'servico'])],
            'itens.*.id' => ['required', 'integer'],
            'itens.*.variante_id' => ['nullable', 'integer'],
            'itens.*.quantidade' => ['required', 'integer', 'min:1', 'max:9999'],
            'data_limite' => ['nullable', 'date'],
            'observacao' => ['nullable', 'string', 'max:1000'],
            'pagar_agora' => ['sometimes', 'boolean'],
            'metodo_pagamento_id' => ['required_if:pagar_agora,true,1', 'nullable', 'integer', Rule::exists('metodos_pagamento', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId))],
            'data_pagamento' => ['required_if:pagar_agora,true,1', 'nullable', 'date'],
            'referencia' => ['nullable', 'string', 'max:100'],
            'comprovativo' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'matricula_id.required' => 'Escolha o aluno (matrícula).',
            'itens.required' => 'Escolha pelo menos um produto ou serviço.',
            'itens.min' => 'Escolha pelo menos um produto ou serviço.',
            'itens.*.quantidade.min' => 'A quantidade mínima é 1.',
            'metodo_pagamento_id.required_if' => 'Escolha o método de pagamento.',
            'data_pagamento.required_if' => 'Indique a data do pagamento.',
        ];
    }
}
