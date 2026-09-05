<?php

namespace Modules\Core\Http\Requests\Horario;

use Illuminate\Foundation\Http\FormRequest;

class AtualizarHorarioRequest extends FormRequest
{
    /**
     * Determina se o utilizador está autorizado a realizar esta ação.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('horario.editar') ?? false;
    }

    /**
     * Regras de validação para atualização.
     */
    public function rules(): array
    {
        return [
            'nome' => [
                'required',
                'string',
                'max:100',
            ],

            'hora_inicio' => [
                'required',
                'date_format:H:i',
            ],

            'hora_fim' => [
                'required',
                'date_format:H:i',
                'after:hora_inicio',
            ],

            'estado' => [
                'sometimes',
                'integer',
                'in:0,1',
            ],
        ];
    }

    /**
     * Mensagens personalizadas.
     */
    public function messages(): array
    {
        return [
            'nome.required' => 'O nome do horário é obrigatório.',
            'nome.max' => 'O nome do horário não pode ter mais de 100 caracteres.',

            'hora_inicio.required' => 'A hora de início é obrigatória.',
            'hora_inicio.date_format' => 'A hora de início deve estar no formato HH:MM.',

            'hora_fim.required' => 'A hora de fim é obrigatória.',
            'hora_fim.date_format' => 'A hora de fim deve estar no formato HH:MM.',
            'hora_fim.after' => 'A hora de fim deve ser posterior à hora de início.',

            'estado.in' => 'O estado informado é inválido.',
        ];
    }
}

