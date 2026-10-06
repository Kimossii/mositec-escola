<?php

namespace Modules\Turma\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Turma\Models\NivelAcademico;

class CriarTurmaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('turmas.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'ano_lectivo_id' => 'required|integer|exists:ano_lectivos,id',
            'nivel_academico_id' => 'required|integer|exists:niveis_academicos,id',
            'curso_id' => [
                Rule::requiredIf(function () {
                    $nivel = NivelAcademico::find($this->input('nivel_academico_id'));

                    return $nivel && $nivel->etapa_ensino->exigeCurso();
                }),
                'nullable',
                'integer',
                'exists:cursos,id',
            ],
            'codigo' => 'required|string|max:50',
            'nome' => 'required|string|max:255',
            'turno_id' => 'nullable|integer|exists:turnos,id',
        ];
    }

    public function messages(): array
    {
        return [
            'ano_lectivo_id.required' => 'O ano lectivo é obrigatório.',
            'ano_lectivo_id.exists' => 'O ano lectivo indicado não existe.',

            'nivel_academico_id.required' => 'O nível académico é obrigatório.',
            'nivel_academico_id.exists' => 'O nível académico indicado não existe.',

            'curso_id.required' => 'O curso é obrigatório.',
            'curso_id.exists' => 'O curso indicado não existe.',

            'codigo.required' => 'O código da turma é obrigatório.',
            'codigo.max' => 'O código da turma não pode ultrapassar 50 caracteres.',

            'nome.required' => 'O nome da turma é obrigatório.',
            'nome.max' => 'O nome da turma não pode ultrapassar 255 caracteres.',

            'turno_id.exists' => 'O turno indicado não existe.',
        ];
    }
}
