<?php

namespace Modules\Matricula\Http\Requests;

use Modules\Core\Tenancy\ExisteNaEscola;
use App\Http\Requests\BaseRequest;

class CriarMatriculaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('matricula.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'turma_id' => [
                'required',
                'integer',
                ExisteNaEscola::em('turmas'),
            ],
            'ano_lectivo_id' => [
                'required',
                'integer',
                ExisteNaEscola::em('ano_lectivos'),
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

    public function messages(): array
    {
        return [
            'turma_id.required' => 'A turma é obrigatória.',
            'turma_id.exists' => 'A turma seleccionada não existe.',
            'ano_lectivo_id.required' => 'O ano lectivo é obrigatório.',
            'ano_lectivo_id.exists' => 'O ano lectivo seleccionado não existe.',
            'data_matricula.date' => 'A data de matrícula é inválida.',
        ];
    }
}
