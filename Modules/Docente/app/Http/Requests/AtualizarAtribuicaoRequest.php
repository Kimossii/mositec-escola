<?php

namespace Modules\Docente\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Docente\Enums\PapelDocente;

class AtualizarAtribuicaoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('atribuicao-docente.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'papel' => ['required', Rule::enum(PapelDocente::class)],
            'data_inicio' => ['required', 'date_format:Y-m-d'],
            'data_fim' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function messages(): array
    {
        return [
            'papel.required' => 'O papel é obrigatório.',
            'papel.enum' => 'O papel indicado é inválido.',
            'data_inicio.required' => 'A data de início é obrigatória.',
            'data_inicio.date_format' => 'A data de início tem de estar no formato AAAA-MM-DD.',
            'data_fim.date_format' => 'A data de fim tem de estar no formato AAAA-MM-DD.',
        ];
    }
}
