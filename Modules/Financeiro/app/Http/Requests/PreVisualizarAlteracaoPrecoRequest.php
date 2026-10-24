<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Modules\Financeiro\Http\Requests\Concerns\ValidaAlteracaoPreco;

class PreVisualizarAlteracaoPrecoRequest extends BaseRequest
{
    use ValidaAlteracaoPreco;

    public function rules(): array
    {
        return $this->regrasDoPedido();
    }

    public function messages(): array
    {
        return $this->mensagensDoPedido();
    }
}
