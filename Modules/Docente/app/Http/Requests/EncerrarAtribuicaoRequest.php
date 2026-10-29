<?php

namespace Modules\Docente\Http\Requests;

use App\Http\Requests\BaseRequest;

class EncerrarAtribuicaoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('atribuicao-docente.encerrar') ?? false;
    }

    public function rules(): array
    {
        return [
            'data_fim' => ['required', 'date_format:Y-m-d'],
            'motivo' => ['required', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'data_fim.required' => 'A data de fim é obrigatória.',
            'data_fim.date_format' => 'A data de fim tem de estar no formato AAAA-MM-DD.',
            'motivo.required' => 'Indique o motivo do encerramento.',
            'motivo.max' => 'O motivo não pode ultrapassar 500 caracteres.',
        ];
    }
}
