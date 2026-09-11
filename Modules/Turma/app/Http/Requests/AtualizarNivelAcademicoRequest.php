<?php

namespace Modules\Turma\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;

class AtualizarNivelAcademicoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('turmas.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'codigo' => 'required|string|max:50',
            'nome' => 'required|string|max:255',
            'ordem' => 'required|integer|min:1',
            'etapa_ensino' => [
                'required',
                'integer',
                Rule::in(
                    Estabelecimento::current()?->etapasEnsino()->pluck('etapa_ensino')
                        ->map(fn (EtapaEnsinoEnum $e) => $e->value)->all() ?? []
                ),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'codigo.required' => 'O código do nível académico é obrigatório.',
            'codigo.max' => 'O código do nível académico não pode ultrapassar 50 caracteres.',
            'nome.required' => 'O nome do nível académico é obrigatório.',
            'nome.max' => 'O nome do nível académico não pode ultrapassar 255 caracteres.',
            'ordem.required' => 'A ordem do nível académico é obrigatória.',
            'ordem.min' => 'A ordem deve ser igual ou superior a 1.',
            'etapa_ensino.required' => 'A etapa de ensino é obrigatória.',
            'etapa_ensino.in' => 'A etapa de ensino indicada não está configurada para este estabelecimento.',
        ];
    }
}
