<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;

class TotalDevidoRequest extends BaseRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ate_ano' => ['nullable', 'required_with:ate_mes', 'integer', 'between:2000,2100'],
            'ate_mes' => ['nullable', 'required_with:ate_ano', 'integer', 'between:1,12'],
        ];
    }
}
