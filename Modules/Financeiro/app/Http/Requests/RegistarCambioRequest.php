<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Contracts\Validation\Validator;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\Rules\TaxaDeCambio;
use Modules\Financeiro\Services\CambioDoDia;
use Modules\Financeiro\Services\MoedaDoTenant;

class RegistarCambioRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('moeda-cambio.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'data' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . app(RelogioDoTenant::class)->hoje()->toDateString()],
            'taxa' => ['required', new TaxaDeCambio()],
        ];
    }

    public function messages(): array
    {
        return [
            'data.required' => 'A data do câmbio é obrigatória.',
            'data.date_format' => 'A data do câmbio é inválida: use o formato AAAA-MM-DD.',
            'data.before_or_equal' => 'A data do câmbio não pode ser futura.',
            'taxa.required' => 'A taxa de câmbio é obrigatória.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (app(MoedaDoTenant::class)->atual()->codigo === CambioDoDia::REFERENCIA) {
                $validator->errors()->add('taxa', 'Com USD como moeda da escola não é preciso registar câmbio: vale sempre 1.');
            }
        });
    }
}
