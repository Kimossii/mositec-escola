<?php

namespace Modules\AnoLectivo\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\Estabelecimento\Models\Estabelecimento;

class AtualizarAnoLectivoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gerir-ano-letivo') ?? false;
    }

    public function rules(): array
    {
        return [
            'nome' => [
                'required',
                'string',
                'max:255',
                Rule::unique('ano_lectivos', 'nome')
                    ->where(fn ($query) => $query->where('estabelecimento_id', Estabelecimento::current()?->id))
                    ->ignore($this->route('anoLectivo')),
            ],
            'data_inicio' => ['required', 'date'],
            'data_fim' => ['required', 'date', 'after_or_equal:data_inicio'],
            'estado' => ['required', new Enum(EstadoAnoLectivo::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'nome.required' => 'O nome do ano lectivo é obrigatório.',
            'nome.unique' => 'Já existe um Ano Lectivo com este nome neste estabelecimento.',
            'data_inicio.required' => 'A data de início é obrigatória.',
            'data_inicio.date' => 'A data de início tem de ser uma data válida.',
            'data_fim.required' => 'A data de fim é obrigatória.',
            'data_fim.date' => 'A data de fim tem de ser uma data válida.',
            'data_fim.after_or_equal' => 'A data de fim não pode ser anterior à data de início.',
            'estado.required' => 'O estado do ano lectivo é obrigatório.',
        ];
    }
}
