<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Modules\Financeiro\Http\Requests\Concerns\ValidaMotivoDeAnulacao;

class AnularPropinaRequest extends BaseRequest
{
    use ValidaMotivoDeAnulacao;

    public function authorize(): bool
    {
        return $this->user()?->can('propina.anular') ?? false;
    }
}
