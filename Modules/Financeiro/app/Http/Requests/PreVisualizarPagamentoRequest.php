<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Modules\Financeiro\Http\Requests\Concerns\ValidaPagamento;

class PreVisualizarPagamentoRequest extends BaseRequest
{
    use ValidaPagamento;
}
