<?php

namespace Modules\Avaliacao\Http\Requests;

use App\Http\Requests\BaseRequest;

class GuardarConfigDocumentosRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('nota.configurar') ?? false;
    }

    public function rules(): array
    {
        return [
            'linhas_extra' => ['nullable', 'string', 'max:1000'],
            'lema' => ['nullable', 'string', 'max:200'],
            'visto_cargo' => ['nullable', 'string', 'max:120'],
            'visto_nome' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return MensagensDeAvaliacao::todas();
    }
}
