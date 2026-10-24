<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Modules\Financeiro\Http\Requests\Concerns\ValidaComprovativo;
use Modules\Financeiro\Http\Requests\Concerns\ValidaPagamento;

class RegistarPagamentoRequest extends BaseRequest
{
    use ValidaComprovativo;
    use ValidaPagamento;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge($this->regrasDoPagamento(), $this->regrasDoComprovativo());
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return array_merge($this->mensagensDoPagamento(), $this->mensagensDoComprovativo());
    }
}
