<?php

namespace Modules\Horario\Http\Requests;

use App\Http\Requests\BaseRequest;

class EncerrarEntradaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('grelha-horaria.encerrar') ?? false;
    }

    public function rules(): array
    {
        return ['vigencia_fim' => ['required', 'date_format:Y-m-d']];
    }

    public function messages(): array
    {
        return [
            'vigencia_fim.required' => 'A data de fim é obrigatória.',
            'vigencia_fim.date_format' => 'A data de fim tem de estar no formato AAAA-MM-DD.',
        ];
    }
}
