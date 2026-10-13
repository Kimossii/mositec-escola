<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Modules\Financeiro\Http\Requests\Concerns\ValidaGeracaoPropinas;

class GerarPropinasRequest extends BaseRequest
{
    use ValidaGeracaoPropinas;
}
