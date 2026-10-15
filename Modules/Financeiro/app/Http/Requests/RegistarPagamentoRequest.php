<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Modules\Financeiro\Http\Requests\Concerns\ValidaPagamento;

class RegistarPagamentoRequest extends BaseRequest
{
    use ValidaPagamento;
}
