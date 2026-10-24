<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Tenancy\TenantContext;

class PagarCobrancaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cobranca.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'metodo_pagamento_id' => ['required', 'integer', Rule::exists('metodos_pagamento', 'id')->where(fn ($q) => $q->where('tenant_id', app(TenantContext::class)->id()))],
            'data_pagamento' => ['required', 'date'],
            'referencia' => ['nullable', 'string', 'max:100'],
            'comprovativo' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'metodo_pagamento_id.required' => 'Escolha o método de pagamento.',
            'data_pagamento.required' => 'Indique a data do pagamento.',
        ];
    }
}
