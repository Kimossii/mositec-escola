<?php

namespace Modules\Turma\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Estabelecimento\Models\Estabelecimento;

class AtualizarTurnoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('turmas.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'nome' => [
                'required',
                'string',
                'max:255',
                Rule::unique('turnos', 'nome')
                    ->ignore($this->route('turno'))
                    ->where(fn ($query) => $query->where('estabelecimento_id', Estabelecimento::current()?->id)),
            ],
            'descricao' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'nome.required' => 'O nome do turno é obrigatório.',
            'nome.string' => 'O nome do turno deve ser um texto válido.',
            'nome.max' => 'O nome do turno não pode ultrapassar 255 caracteres.',
            'nome.unique' => 'Já existe um turno com este nome neste estabelecimento.',
            'descricao.string' => 'A descrição do turno deve ser um texto válido.',
        ];
    }
}
