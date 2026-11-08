<?php

namespace Modules\Avaliacao\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Avaliacao\Enums\TipoInstrumento;

class DefinirPrazoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('nota.configurar') ?? false;
    }

    public function rules(): array
    {
        return [
            'periodo_id' => ['required', 'integer'],
            'tipo' => ['required', Rule::in(array_column(TipoInstrumento::cases(), 'value'))],
            'data_limite' => ['required', 'date_format:Y-m-d'],
        ];
    }

    public function messages(): array
    {
        return MensagensDeAvaliacao::todas();
    }
}
