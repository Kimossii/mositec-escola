<?php

namespace Modules\Matricula\Http\Requests;

use App\Http\Requests\BaseRequest;

class AtualizarMatriculaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('matricula.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'turma_id' => [
                'required',
                'integer',
                'exists:turmas,id',
            ],
            'ano_lectivo_id' => [
                'required',
                'integer',
                'exists:anos_lectivos,id',
            ],
            'data_matricula' => [
                'required',
                'date',
            ],
            'observacoes' => [
                'nullable',
                'string',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'turma_id.required' => 'A turma é obrigatória.',
            'turma_id.exists' => 'A turma seleccionada não existe.',
            'ano_lectivo_id.required' => 'O ano lectivo é obrigatório.',
            'ano_lectivo_id.exists' => 'O ano lectivo seleccionado não existe.',
            'data_matricula.required' => 'A data de matrícula é obrigatória.',
            'data_matricula.date' => 'A data de matrícula é inválida.',
        ];
    }
}
