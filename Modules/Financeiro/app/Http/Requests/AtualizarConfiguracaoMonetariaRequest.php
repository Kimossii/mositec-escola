<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Financeiro\Support\Moeda;

class AtualizarConfiguracaoMonetariaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('moeda-cambio.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'moeda' => ['required', 'string', Rule::in(array_column(Moeda::opcoes(), 'value'))],
            'cambio_manual' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'moeda.required' => 'A moeda é obrigatória.',
            'moeda.in' => 'A moeda escolhida não existe no registo de moedas.',
            'cambio_manual.required' => 'Indique se usa câmbio próprio.',
            'cambio_manual.boolean' => 'O modo de câmbio é inválido.',
        ];
    }
}
