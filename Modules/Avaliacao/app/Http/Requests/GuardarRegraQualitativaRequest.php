<?php

namespace Modules\Avaliacao\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Avaliacao\Enums\Progressao;

class GuardarRegraQualitativaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('nota.configurar') ?? false;
    }

    public function rules(): array
    {
        return [
            'ano_lectivo_id' => ['required', 'integer'],
            'nivel_academico_id' => ['required', 'integer'],
            'escala_id' => ['required', 'integer'],
            'progressao' => ['nullable', Rule::in(array_column(Progressao::cases(), 'value'))],
        ];
    }

    public function messages(): array
    {
        return MensagensDeAvaliacao::todas();
    }
}
