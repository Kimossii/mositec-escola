<?php

namespace Modules\Turma\Http\Requests;

use App\Http\Requests\BaseRequest;

class CriarTurnoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('turmas.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'nome' => 'required|string|max:255',
            'descricao' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'nome.required' => 'O nome do turno é obrigatório.',
            'nome.string' => 'O nome do turno deve ser um texto válido.',
            'nome.max' => 'O nome do turno não pode ultrapassar 255 caracteres.',
            'descricao.string' => 'A descrição do turno deve ser um texto válido.',
        ];
    }
}
