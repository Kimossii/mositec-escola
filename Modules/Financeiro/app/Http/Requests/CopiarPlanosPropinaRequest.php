<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Tenancy\TenantContext;

class CopiarPlanosPropinaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('plano-propina.criar') ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();
        $anoDoTenant = fn () => Rule::exists('ano_lectivos', 'id')
            ->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'));

        return [
            'ano_origem_id' => ['required', 'integer', $anoDoTenant()],
            'ano_destino_id' => ['required', 'integer', 'different:ano_origem_id', $anoDoTenant()],
        ];
    }

    public function messages(): array
    {
        return [
            'ano_origem_id.required' => 'O ano lectivo de origem é obrigatório.',
            'ano_origem_id.exists' => 'O ano lectivo de origem é inválido.',
            'ano_destino_id.required' => 'O ano lectivo de destino é obrigatório.',
            'ano_destino_id.exists' => 'O ano lectivo de destino é inválido.',
            'ano_destino_id.different' => 'O ano lectivo de destino tem de ser diferente do de origem.',
        ];
    }
}
