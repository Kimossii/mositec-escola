<?php

namespace Modules\Curso\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Estabelecimento\Models\Estabelecimento;

class CriarCursoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('curso.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'codigo' => [
                'required',
                'string',
                'max:50',
                Rule::unique('cursos', 'codigo')
                    ->where(fn ($query) => $query->where('estabelecimento_id', Estabelecimento::current()?->id)),
            ],
            'nome' => [
                'required',
                'string',
                'max:255',
                Rule::unique('cursos', 'nome')
                    ->where(fn ($query) => $query->where('estabelecimento_id', Estabelecimento::current()?->id)),
            ],
            'descricao' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'codigo.required' => 'O código do curso é obrigatório.',
            'codigo.unique' => 'Já existe um curso com este código neste estabelecimento.',
            'codigo.max' => 'O código do curso não pode ultrapassar 50 caracteres.',
            'nome.required' => 'O nome do curso é obrigatório.',
            'nome.unique' => 'Já existe um curso com este nome neste estabelecimento.',
            'nome.max' => 'O nome do curso não pode ultrapassar 255 caracteres.',
        ];
    }
}
