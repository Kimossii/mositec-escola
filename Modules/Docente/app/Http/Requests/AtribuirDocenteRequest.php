<?php

namespace Modules\Docente\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Docente\Enums\PapelDocente;

class AtribuirDocenteRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('atribuicao-docente.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'docente_id' => ['required', 'integer', 'exists:docentes,id'],
            'turma_id' => ['required', 'integer', 'exists:turmas,id'],
            'disciplina_id' => ['required', 'integer', 'exists:disciplinas,id'],
            'papel' => ['required', Rule::enum(PapelDocente::class)],
            'data_inicio' => ['required', 'date_format:Y-m-d'],
            'data_fim' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function messages(): array
    {
        return [
            'docente_id.required' => 'O docente é obrigatório.',
            'docente_id.integer' => 'O docente indicado é inválido.',
            'docente_id.exists' => 'O docente indicado não existe.',
            'turma_id.required' => 'A turma é obrigatória.',
            'turma_id.integer' => 'A turma indicada é inválida.',
            'turma_id.exists' => 'A turma indicada não existe.',
            'disciplina_id.required' => 'A disciplina é obrigatória.',
            'disciplina_id.integer' => 'A disciplina indicada é inválida.',
            'disciplina_id.exists' => 'A disciplina indicada não existe.',
            'papel.required' => 'O papel é obrigatório.',
            'papel.enum' => 'O papel indicado é inválido.',
            'data_inicio.required' => 'A data de início é obrigatória.',
            'data_inicio.date_format' => 'A data de início tem de estar no formato AAAA-MM-DD.',
            'data_fim.date_format' => 'A data de fim tem de estar no formato AAAA-MM-DD.',
        ];
    }
}
