<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Financeiro\DTO\LinhaDeDistribuicao;
use Modules\Financeiro\Rules\ValorMonetario;

class AplicarCreditoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pagamento.criar') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'valor_credito' => ['required', new ValorMonetario(false)],
            'modo_distribuicao' => ['required', Rule::in([LinhaDeDistribuicao::AUTOMATICA, LinhaDeDistribuicao::EXPLICITA])],
            'distribuicao' => ['exclude_unless:modo_distribuicao,' . LinhaDeDistribuicao::EXPLICITA, 'present', 'array', 'max:60'],
            'distribuicao.*.propina_id' => ['required', 'integer', 'distinct'],
            'distribuicao.*.valor' => ['required', new ValorMonetario(false)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'valor_credito.required' => 'Indique quanto crédito aplicar.',
            'distribuicao.*.propina_id.distinct' => 'Cada propina só pode aparecer uma vez na distribuição.',
        ];
    }
}
