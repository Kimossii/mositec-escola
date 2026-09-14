<?php

namespace Modules\Matricula\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CriarMatriculaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
                'nullable',
                'date',
            ],
            'estado' => [
                'nullable',
                'integer',
            ],
            'observacoes' => [
                'nullable',
                'string',
            ],
        ];
    }
}
