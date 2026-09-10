<?php

namespace Modules\Aluno\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;

class AtualizarAlunoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('aluno.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'nome_completo' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'telefone' => ['nullable', 'string', 'max:50'],
            'data_nascimento' => ['nullable', 'date'],
            'sexo' => ['nullable', 'integer', Rule::in([0, 1, 2])],
        ];
    }

    public function messages(): array
    {
        return [
            'nome_completo.required' => 'O nome completo é obrigatório.',
        ];
    }
}
