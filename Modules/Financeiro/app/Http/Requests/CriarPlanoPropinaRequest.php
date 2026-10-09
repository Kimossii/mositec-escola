<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Tenancy\TenantContext;
use Modules\Financeiro\Http\Requests\Concerns\ValidaPlanoPropina;

class CriarPlanoPropinaRequest extends BaseRequest
{
    use ValidaPlanoPropina;

    public function authorize(): bool
    {
        return $this->user()?->can('plano-propina.criar') ?? false;
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
            'nome' => [
                'required',
                'string',
                'max:100',
                Rule::unique('planos_propina', 'nome')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('ano_lectivo_id', $this->input('ano_lectivo_id'))),
            ],
        ]);
    }

    public function messages(): array
    {
        return $this->mensagensComuns();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('ano_lectivo_id')) {
                return;
            }

            $ano = AnoLectivo::find((int) $this->input('ano_lectivo_id'));

            if ($ano !== null) {
                $this->validarCoerencia($validator, $ano, null);
            }
        });
    }
}
