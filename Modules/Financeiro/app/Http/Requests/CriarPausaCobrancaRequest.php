<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Modules\Core\Tenancy\TenantContext;
use Modules\Financeiro\Http\Requests\Concerns\ValidaPausaCobranca;

class CriarPausaCobrancaRequest extends BaseRequest
{
    use ValidaPausaCobranca;

    public function authorize(): bool
    {
        return $this->user()?->can('pausa-cobranca.criar') ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();

        return array_merge($this->regrasComuns(), [
            'ano_lectivo_id' => [
                'required',
                'integer',
                Rule::exists('ano_lectivos', 'id')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at')),
            ],
        ]);
    }

    public function messages(): array
    {
        return array_merge($this->mensagensComuns(), [
            'ano_lectivo_id.required' => 'O ano lectivo é obrigatório.',
            'ano_lectivo_id.exists' => 'O ano lectivo escolhido é inválido.',
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('ano_lectivo_id')) {
                return;
            }

            $this->validarDatas($validator, (int) $this->input('ano_lectivo_id'));
        });
    }
}
