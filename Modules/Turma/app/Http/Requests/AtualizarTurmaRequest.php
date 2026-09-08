<?php

namespace Modules\Turma\Http\Requests;

use App\Http\Requests\BaseRequest;

class AtualizarTurmaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('turmas.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'nivel_academico_id' => 'required|integer|exists:niveis_academicos,id',
            'codigo' => 'required|string|max:50',
            'nome' => 'required|string|max:255',
            'turno_id' => 'nullable|integer|exists:turnos,id',
        ];
    }

    public function messages(): array
    {
        return [
            'nivel_academico_id.required' => 'O nível académico é obrigatório.',
            'nivel_academico_id.exists' => 'O nível académico indicado não existe.',

            'codigo.required' => 'O código da turma é obrigatório.',
            'codigo.max' => 'O código da turma não pode ultrapassar 50 caracteres.',

            'nome.required' => 'O nome da turma é obrigatório.',
            'nome.max' => 'O nome da turma não pode ultrapassar 255 caracteres.',

            'turno_id.exists' => 'O turno indicado não existe.',
        ];
    }
}
