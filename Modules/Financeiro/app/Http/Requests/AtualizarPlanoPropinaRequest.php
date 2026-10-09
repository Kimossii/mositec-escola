<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Modules\Core\Tenancy\TenantContext;
use Modules\Financeiro\Http\Requests\Concerns\ValidaPlanoPropina;

class AtualizarPlanoPropinaRequest extends BaseRequest
{
    use ValidaPlanoPropina;

    public function authorize(): bool
    {
        return $this->user()?->can('plano-propina.editar') ?? false;
    }

    public function rules(): array
    {
        $plano = $this->route('plano');

        return array_merge($this->regrasComuns(), [
            'nome' => [
                'required',
                'string',
                'max:100',
                Rule::unique('planos_propina', 'nome')
                    ->where(fn ($query) => $query
                        ->where('tenant_id', app(TenantContext::class)->id())
                        ->where('ano_lectivo_id', $plano?->ano_lectivo_id))
                    ->ignore($plano?->id),
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
            $plano = $this->route('plano');

            if ($plano->anoLectivo !== null) {
                $this->validarCoerencia($validator, $plano->anoLectivo, (int) $plano->id);
            }
        });
    }
}
