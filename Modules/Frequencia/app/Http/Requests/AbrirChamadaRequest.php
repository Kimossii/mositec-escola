<?php

namespace Modules\Frequencia\Http\Requests;

use App\Http\Requests\BaseRequest;
use Modules\Core\Tenancy\ExisteNaEscola;

class AbrirChamadaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('frequencia.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'turma_id' => ['required', 'integer', ExisteNaEscola::em('turmas')],
            'data' => ['required', 'date_format:Y-m-d'],
            'horario_id' => ['nullable', 'integer', ExisteNaEscola::em('horarios')],
        ];
    }

    public function messages(): array
    {
        return [
            'turma_id.required' => 'A turma é obrigatória.',
            'turma_id.exists' => 'A turma indicada não existe.',
            'data.required' => 'A data é obrigatória.',
            'data.date_format' => 'A data tem de estar no formato AAAA-MM-DD.',
            'horario_id.exists' => 'O tempo indicado não existe.',
        ];
    }
}
