<?php

namespace Modules\Usuario\Http\Requests;

use App\Http\Requests\BaseRequest;

class ProcurarAlunoPorMatriculaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        // A permissão (usuario.criar) e o throttle estão na rota.
        return true;
    }

    public function rules(): array
    {
        return [
            'matricula' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9\-\/.]+$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'matricula.required' => 'Indique o número de matrícula.',
            'matricula.string' => 'O número de matrícula deve ser um texto válido.',
            'matricula.max' => 'O número de matrícula não pode ter mais de 30 caracteres.',
            'matricula.regex' => 'O número de matrícula tem caracteres inválidos.',
        ];
    }
}
