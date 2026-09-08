<?php

namespace Modules\Estabelecimento\Http\Requests;

use App\Http\Requests\BaseRequest;

class AtualizarDadosRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('estabelecimento.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'nome' => 'required|string|max:255',
            'nome_abreviado' => 'nullable|string|max:100',
            'tipo' => 'required|integer|in:1,2,3',
            'nif' => 'nullable|string|max:50',
            'codigo_mined' => 'nullable|string|max:50',
            'numero_alvara' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'telefone' => 'nullable|string|max:30',
            'telefone_alternativo' => 'nullable|string|max:30',
            'website' => 'nullable|string|max:255',
            'endereco' => 'nullable|string|max:255',
            'caixa_postal' => 'nullable|string|max:50',
            'municipio' => 'nullable|string|max:100',
            'provincia' => 'nullable|string|max:100',
            'responsavel_nome' => 'nullable|string|max:255',
            'responsavel_cargo' => 'nullable|string|max:100',
            'ano_fundacao' => 'nullable|integer|min:1900|max:' . (int) date('Y'),
            'observacoes' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'nome.required' => 'O nome do estabelecimento é obrigatório.',
            'tipo.required' => 'O tipo de estabelecimento é obrigatório.',
            'tipo.in' => 'O tipo de estabelecimento indicado é inválido.',
            'email.email' => 'Informe um email válido.',
            'ano_fundacao.integer' => 'O ano de fundação deve ser um número válido.',
        ];
    }
}
