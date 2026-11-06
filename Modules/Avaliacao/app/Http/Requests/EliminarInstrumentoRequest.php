<?php

namespace Modules\Avaliacao\Http\Requests;

use App\Http\Requests\BaseRequest;

class EliminarInstrumentoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('nota.editar') ?? false;
    }

    public function rules(): array
    {
        return [];
    }

    public function messages(): array
    {
        return MensagensDeAvaliacao::todas();
    }
}
