<?php

namespace Modules\AnoLectivo\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Modules\AnoLectivo\Enums\TipoPeriodo;

class AtualizarPeriodoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gerir-ano-letivo') ?? false;
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'tipo' => ['required', new Enum(TipoPeriodo::class)],
            'data_inicio' => ['required', 'date'],
            'data_fim' => ['required', 'date', 'after_or_equal:data_inicio'],
            'numero' => [
                'nullable',
                'integer',
                'min:1',
                Rule::unique('periodos', 'numero')
                    ->where(fn ($query) => $query->where('ano_lectivo_id', $this->route('periodo')?->ano_lectivo_id))
                    ->ignore($this->route('periodo')),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'data_fim.after_or_equal' => 'A data de fim não pode ser anterior à data de início.',
            'numero.unique' => 'Já existe um período com este número neste Ano Lectivo.',
        ];
    }
}
