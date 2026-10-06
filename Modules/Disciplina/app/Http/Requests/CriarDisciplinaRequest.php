<?php

namespace Modules\Disciplina\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Estabelecimento\Models\Estabelecimento;

class CriarDisciplinaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('disciplina.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'codigo' => [
                'required',
                'string',
                'max:50',
                Rule::unique('disciplinas', 'codigo')
                    ->where(fn ($query) => $query->where('estabelecimento_id', Estabelecimento::current()?->id)),
            ],
            'nome' => [
                'required',
                'string',
                'max:255',
                Rule::unique('disciplinas', 'nome')
                    ->where(fn ($query) => $query->where('estabelecimento_id', Estabelecimento::current()?->id)),
            ],
            'descricao' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'codigo.required' => 'O código da disciplina é obrigatório.',
            'codigo.unique' => 'Já existe uma disciplina com este código neste estabelecimento.',
            'codigo.max' => 'O código da disciplina não pode ultrapassar 50 caracteres.',
            'nome.required' => 'O nome da disciplina é obrigatório.',
            'nome.unique' => 'Já existe uma disciplina com este nome neste estabelecimento.',
            'nome.max' => 'O nome da disciplina não pode ultrapassar 255 caracteres.',
        ];
    }
}
