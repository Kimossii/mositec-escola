<?php

namespace Modules\Disciplina\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Estabelecimento\Models\Estabelecimento;

class AtualizarDisciplinaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('disciplina.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'codigo' => [
                'required',
                'string',
                'max:50',
                Rule::unique('disciplinas', 'codigo')
                    ->where(fn ($query) => $query->where('estabelecimento_id', Estabelecimento::current()?->id))
                    ->ignore($this->route('disciplina')),
            ],
            'nome' => [
                'required',
                'string',
                'max:255',
                Rule::unique('disciplinas', 'nome')
                    ->where(fn ($query) => $query->where('estabelecimento_id', Estabelecimento::current()?->id))
                    ->ignore($this->route('disciplina')),
            ],
            'descricao' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'codigo.required' => 'O código da disciplina é obrigatório.',
            'codigo.unique' => 'Já existe uma disciplina com este código neste estabelecimento.',
            'nome.required' => 'O nome da disciplina é obrigatório.',
            'nome.unique' => 'Já existe uma disciplina com este nome neste estabelecimento.',
        ];
    }
}
