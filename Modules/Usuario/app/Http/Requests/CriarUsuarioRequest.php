<?php

namespace Modules\Usuario\Http\Requests;

use App\Http\Requests\BaseRequest;

class CriarUsuarioRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'perfil' => 'required|in:admin_escola,secretario,professor,aluno,encarregado',
            'tipo_login' => 'required|in:email,matricula',
            'email' => 'required_if:tipo_login,email|nullable|email|unique:users,email',
            'password' => 'required|min:6',
            'dados_pessoa_id' => 'nullable|exists:dados_pessoas,id',
            'estado' => 'nullable|in:1,0',
            'matriculas_educandos' => 'nullable|array',
            'matriculas_educandos.*' => 'string|exists:users,numero_matricula',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'O nome é obrigatório.',
            'name.string' => 'O nome deve ser um texto válido.',
            'name.max' => 'O nome não pode ter mais de 255 caracteres.',

            'perfil.required' => 'O perfil é obrigatório.',
            'perfil.in' => 'O perfil indicado é inválido.',

            'tipo_login.required' => 'O tipo de login é obrigatório.',
            'tipo_login.in' => 'O tipo de login deve ser email ou matrícula.',

            'email.required_if' => 'O email é obrigatório para este tipo de utilizador.',
            'email.email' => 'Informe um email válido.',
            'email.unique' => 'Este email já está em uso.',

            'password.required' => 'A senha é obrigatória.',
            'password.min' => 'A senha deve ter pelo menos 6 caracteres.',

            'dados_pessoa_id.exists' => 'O registro de dados pessoais não existe.',

            'estado.in' => 'O estado deve ser 1 (ativo) ou 0 (inativo).',

            'matriculas_educandos.*.exists' => 'Uma das matrículas indicadas não existe.',
        ];
    }

}
