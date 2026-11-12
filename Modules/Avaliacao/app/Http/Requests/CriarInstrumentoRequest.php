<?php

namespace Modules\Avaliacao\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Avaliacao\Enums\TipoInstrumento;

class CriarInstrumentoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('nota.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'turma_id' => ['required', 'integer'],
            'disciplina_id' => ['required', 'integer'],
            'periodo_id' => ['required', 'integer'],
            'tipo' => ['required', Rule::in(array_column(TipoInstrumento::cases(), 'value'))],
            'nome' => ['nullable', 'string', 'max:120'],
            'obrigatorio' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return MensagensDeAvaliacao::todas();
    }
}
