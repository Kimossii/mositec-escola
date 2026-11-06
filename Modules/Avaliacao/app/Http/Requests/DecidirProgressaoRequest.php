<?php

namespace Modules\Avaliacao\Http\Requests;

use App\Http\Requests\BaseRequest;

class DecidirProgressaoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('nota.aprovar') ?? false;
    }

    public function rules(): array
    {
        return [
            'matricula_id' => ['required', 'integer'],
            'decisao' => ['required', 'integer', 'in:0,1'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return MensagensDeAvaliacao::todas();
    }
}
