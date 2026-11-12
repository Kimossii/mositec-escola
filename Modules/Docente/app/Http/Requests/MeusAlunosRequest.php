<?php

namespace Modules\Docente\Http\Requests;

use App\Http\Requests\BaseRequest;

class MeusAlunosRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('area-professor.ver') ?? false;
    }

    public function rules(): array
    {
        return [
            'turma' => ['nullable', 'integer'],
            'disciplina' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'turma.integer' => 'A turma indicada é inválida.',
            'disciplina.integer' => 'A disciplina indicada é inválida.',
            'q.max' => 'A pesquisa não pode ultrapassar 100 caracteres.',
        ];
    }

    /** @return array{turma: ?int, disciplina: ?int, q: string} */
    public function filtros(): array
    {
        $d = $this->validated();

        return ['turma' => isset($d['turma']) ? (int) $d['turma'] : null, 'disciplina' => isset($d['disciplina']) ? (int) $d['disciplina'] : null, 'q' => (string) ($d['q'] ?? '')];
    }
}
