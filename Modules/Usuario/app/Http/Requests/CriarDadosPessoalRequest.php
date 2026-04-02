<?php

namespace Modules\Usuario\Http\Requests;

use App\Http\Requests\BaseRequest;

class CriarDadosPessoalRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return true;
    }
    public function rules(): array
    {
        return [
            'nome_completo' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'telefone' => 'nullable|string|max:30',
            'data_nascimento' => 'nullable|date',
            'sexo' => 'nullable|integer|in:0,1,2',
            'numero_identificacao' => 'nullable|string|max:255|unique:dados_pessoas,numero_identificacao',
            'tipo_pessoa' => 'nullable|integer|in:0,1,2,3',
        ];
    }

    public function messages(): array
    {
        return [
            'nome_completo.required' => 'O nome completo é obrigatório.',
            'nome_completo.string' => 'O nome completo deve ser um texto válido.',
            'nome_completo.max' => 'O nome completo não pode ter mais de 255 caracteres.',

            'email.required' => 'O email é obrigatório.',
            'email.email' => 'Informe um email válido.',
            'email.unique' => 'Este email já está em uso.',

            'telefone.string' => 'O telefone deve ser um texto válido.',
            'telefone.max' => 'O telefone não pode ter mais de 30 caracteres.',

            'data_nascimento.date' => 'Informe uma data de nascimento válida.',

            'sexo.integer' => 'O sexo deve ser um número válido.',
            'sexo.in' => 'O sexo deve ser 0 (Masculino), 1 (Feminino) ou 2 (Outro).',

            'numero_identificacao.string' => 'O número de identificação deve ser válido.',
            'numero_identificacao.max' => 'O número de identificação não pode ter mais de 255 caracteres.',
            'numero_identificacao.unique' => 'Este número de identificação já está cadastrado.',

            'tipo_pessoa.integer' => 'O tipo de pessoa deve ser um número válido.',
            'tipo_pessoa.in' => 'O tipo de pessoa deve ser 0, 1, 2 ou 3.',
        ];
    }
}
