<?php

namespace Modules\Avaliacao\Http\Requests;

use App\Http\Requests\BaseRequest;

class ExportarPautaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('nota.exportar') ?? false;
    }

    public function rules(): array
    {
        return [
            'formato' => ['required', 'string', 'in:pdf,excel'],
        ];
    }

    public function messages(): array
    {
        return MensagensDeAvaliacao::todas();
    }
}
