<?php

namespace Modules\Horario\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Tenancy\ExisteNaEscola;
use Modules\Horario\Enums\DiaSemana;

/** Criar (POST) e alterar (PUT: nova versão; a turma é a da entrada e o `turma_id` do pedido é ignorado). */
class GuardarEntradaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can($this->isMethod('PUT') ? 'grelha-horaria.editar' : 'grelha-horaria.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'turma_id' => [$this->isMethod('PUT') ? 'sometimes' : 'required', 'integer', ExisteNaEscola::em('turmas')],
            'dia_semana' => ['required', Rule::enum(DiaSemana::class)],
            'horario_id' => ['required', 'integer', ExisteNaEscola::em('horarios')],
            'disciplina_id' => ['required', 'integer', ExisteNaEscola::em('disciplinas')],
            'sala_id' => ['nullable', 'integer', ExisteNaEscola::em('salas')],
            'vigencia_inicio' => ['required', 'date_format:Y-m-d'],
            'vigencia_fim' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function messages(): array
    {
        return [
            'turma_id.required' => 'A turma é obrigatória.',
            'turma_id.integer' => 'A turma indicada é inválida.',
            'turma_id.exists' => 'A turma indicada não existe.',
            'dia_semana.required' => 'O dia da semana é obrigatório.',
            'dia_semana.enum' => 'Escolha um dia entre segunda-feira e sábado.',
            'horario_id.required' => 'O tempo de aula é obrigatório.',
            'horario_id.integer' => 'O tempo indicado é inválido.',
            'horario_id.exists' => 'O tempo indicado não existe.',
            'disciplina_id.required' => 'A disciplina é obrigatória.',
            'disciplina_id.integer' => 'A disciplina indicada é inválida.',
            'disciplina_id.exists' => 'A disciplina indicada não existe.',
            'sala_id.integer' => 'A sala indicada é inválida.',
            'sala_id.exists' => 'A sala indicada não existe.',
            'vigencia_inicio.required' => 'A data de início é obrigatória.',
            'vigencia_inicio.date_format' => 'A data de início tem de estar no formato AAAA-MM-DD.',
            'vigencia_fim.date_format' => 'A data de fim tem de estar no formato AAAA-MM-DD.',
        ];
    }
}
