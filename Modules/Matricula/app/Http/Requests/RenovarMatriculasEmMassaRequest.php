<?php

namespace Modules\Matricula\Http\Requests;

use App\Http\Requests\BaseRequest;

class RenovarMatriculasEmMassaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('matricula.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            // Limite alto o suficiente para qualquer selecção realista numa
            // única página da listagem, baixo o suficiente para o pedido
            // síncrono (sem fila) não arriscar timeout.
            'matricula_ids' => ['required', 'array', 'min:1', 'max:200'],
            'matricula_ids.*' => ['integer', 'exists:matriculas,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'matricula_ids.required' => 'Selecciona pelo menos uma matrícula.',
            'matricula_ids.min' => 'Selecciona pelo menos uma matrícula.',
            'matricula_ids.max' => 'Só é possível renovar até 200 matrículas de cada vez.',
        ];
    }
}
