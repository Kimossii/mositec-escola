<?php

namespace Modules\Docente\Http\Requests;

use App\Http\Requests\BaseRequest;

class SubstituirTitularRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('atribuicao-docente.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'turma_id' => ['required', 'integer', 'exists:turmas,id'],
            'disciplina_id' => ['required', 'integer', 'exists:disciplinas,id'],
            'novo_docente_id' => ['required', 'integer', 'exists:docentes,id'],
            'ultimo_dia_do_titular' => ['required', 'date_format:Y-m-d'],
            'motivo' => ['required', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'turma_id.required' => 'A turma é obrigatória.',
            'turma_id.integer' => 'A turma indicada é inválida.',
            'turma_id.exists' => 'A turma indicada não existe.',
            'disciplina_id.required' => 'A disciplina é obrigatória.',
            'disciplina_id.integer' => 'A disciplina indicada é inválida.',
            'disciplina_id.exists' => 'A disciplina indicada não existe.',
            'novo_docente_id.required' => 'O novo docente é obrigatório.',
            'novo_docente_id.integer' => 'O docente indicado é inválido.',
            'novo_docente_id.exists' => 'O docente indicado não existe.',
            'ultimo_dia_do_titular.required' => 'O último dia do titular actual é obrigatório.',
            'ultimo_dia_do_titular.date_format' => 'O último dia do titular tem de estar no formato AAAA-MM-DD.',
            'motivo.required' => 'Indique o motivo da substituição.',
            'motivo.max' => 'O motivo não pode ultrapassar 500 caracteres.',
        ];
    }
}
