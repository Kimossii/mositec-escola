<?php

namespace Modules\Turma\Http\Requests;

use App\Http\Requests\BaseRequest;

class AdicionarHorarioTurnoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('turmas.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'horario_id' => 'required|integer|exists:horarios,id',
            'ordem' => 'required|integer|min:1',
        ];
    }

    public function messages(): array
    {
        return [
            'horario_id.required' => 'O horário é obrigatório.',
            'horario_id.integer' => 'O horário indicado é inválido.',
            'horario_id.exists' => 'O horário indicado não existe.',
            'ordem.required' => 'A ordem é obrigatória.',
            'ordem.integer' => 'A ordem deve ser um número válido.',
            'ordem.min' => 'A ordem deve ser igual ou superior a 1.',
        ];
    }
}
