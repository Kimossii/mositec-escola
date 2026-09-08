<?php

namespace Modules\Turma\Http\Requests;

use App\Http\Requests\BaseRequest;

class CriarNivelAcademicoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('nivel-academico.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'estabelecimento_id' => 'required|integer|exists:estabelecimentos,id',
            'codigo' => 'required|string|max:50',
            'nome' => 'required|string|max:255',
            'ordem' => 'required|integer|min:1',
        ];
    }

    public function messages(): array
    {
        return [
            'estabelecimento_id.required' => 'O estabelecimento é obrigatório.',
            'estabelecimento_id.exists' => 'O estabelecimento indicado não existe.',
            'codigo.required' => 'O código do nível académico é obrigatório.',
            'codigo.max' => 'O código do nível académico não pode ultrapassar 50 caracteres.',
            'nome.required' => 'O nome do nível académico é obrigatório.',
            'nome.max' => 'O nome do nível académico não pode ultrapassar 255 caracteres.',
            'ordem.required' => 'A ordem do nível académico é obrigatória.',
            'ordem.min' => 'A ordem deve ser igual ou superior a 1.',
        ];
    }
}
