<?php

namespace Modules\Avaliacao\Http\Requests;

use App\Http\Requests\BaseRequest;

class ExportarBoletinsRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('nota.exportar') ?? false;
    }

    public function rules(): array
    {
        return [
            'matricula' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return MensagensDeAvaliacao::todas();
    }
}
