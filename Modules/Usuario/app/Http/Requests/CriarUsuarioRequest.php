<?php

namespace Modules\Usuario\Http\Requests;

use App\Http\Requests\BaseRequest;
use Modules\Permissao\Enums\Perfil;

class CriarUsuarioRequest extends BaseRequest
{
    public function authorize(): bool
    {
        // Criar um utilizador com perfil Admin Escola é um acto de
        // autorização, não de simples gestão de contas.
        if ($this->input('perfil') === Perfil::ADMIN_ESCOLA->slug()) {
            return $this->user()?->can('autorizacao.criar') ?? false;
        }

        return $this->user()?->can('usuario.criar') ?? false;
    }

    public function rules(): array
    {
        // Aluno: o servidor só aceita a matrícula (e a senha/estado/permissões). Nome, email, tipo de
        // login, dados pessoais e educandos NÃO estão nas regras, logo nunca chegam a validated().
        if ($this->input('perfil') === Perfil::ALUNO->slug()) {
            return [
                'perfil' => 'required|in:admin_escola,funcionario,professor,aluno,encarregado',
                'numero_matricula' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9\-\/.]+$/'],
                'password' => 'required|min:6|confirmed',
                'estado' => 'nullable|in:1,0',
            ];
        }

        return [
            'name' => 'required|string|max:255',
            'perfil' => 'required|in:admin_escola,funcionario,professor,aluno,encarregado',
            'tipo_login' => 'required|in:email,matricula',
            'email' => 'required_if:tipo_login,email|nullable|email|unique:users,email',
            'password' => 'required|min:6|confirmed',
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
            'password.confirmed' => 'A confirmação da senha não coincide.',

            'dados_pessoa_id.exists' => 'O registro de dados pessoais não existe.',

            'estado.in' => 'O estado deve ser 1 (ativo) ou 0 (inativo).',

            'numero_matricula.required' => 'O número de matrícula do aluno é obrigatório.',
            'numero_matricula.string' => 'O número de matrícula deve ser um texto válido.',
            'numero_matricula.max' => 'O número de matrícula não pode ter mais de 30 caracteres.',
            'numero_matricula.regex' => 'O número de matrícula tem caracteres inválidos.',

            'matriculas_educandos.*.exists' => 'Uma das matrículas indicadas não existe.',
        ];
    }

}
