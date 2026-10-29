<?php

namespace Modules\Usuario\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
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

        // Conta a partir de um docente (decisão E11): a mesma dupla permissão do botão «Criar acesso» em
        // Pessoal → Docentes. Permissão por nome: o Usuario não conhece o módulo Docente.
        if ($this->eProfessorDeDocente()) {
            return ($this->user()?->can('usuario.criar') ?? false) && $this->user()->can('docente.editar');
        }

        return $this->user()?->can('usuario.criar') ?? false;
    }

    private function eProfessorDeDocente(): bool
    {
        return $this->input('perfil') === Perfil::PROFESSOR->slug() && $this->filled('docente_id');
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

        // Professor a partir de um docente sem conta (decisão E6): nome e pessoa vêm do registo do docente; do cliente
        // só o email de login, a senha e o estado. Nome, tipo de login e dados pessoais nunca chegam a validated().
        if ($this->eProfessorDeDocente()) {
            return [
                'perfil' => 'required|in:admin_escola,funcionario,professor,aluno,encarregado',
                'docente_id' => ['required', 'integer'],
                'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
                'password' => 'required|min:6|confirmed',
                'estado' => 'nullable|in:1,0',
            ];
        }

        // `dados_pessoa_id` NÃO está nas regras: ligar uma conta a uma pessoa (aluno, docente) só pelos fluxos que a
        // lêem no servidor, nunca por um id vindo do cliente.
        return [
            'name' => 'required|string|max:255',
            'perfil' => 'required|in:admin_escola,funcionario,professor,aluno,encarregado',
            'tipo_login' => 'required|in:email,matricula',
            'email' => 'required_if:tipo_login,email|nullable|email|unique:users,email',
            'password' => 'required|min:6|confirmed',
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

            'estado.in' => 'O estado deve ser 1 (ativo) ou 0 (inativo).',

            'numero_matricula.required' => 'O número de matrícula do aluno é obrigatório.',
            'numero_matricula.string' => 'O número de matrícula deve ser um texto válido.',
            'numero_matricula.max' => 'O número de matrícula não pode ter mais de 30 caracteres.',
            'numero_matricula.regex' => 'O número de matrícula tem caracteres inválidos.',

            'docente_id.required' => 'Escolha o docente.',
            'docente_id.integer' => 'O docente indicado é inválido.',
            'email.max' => 'O email não pode ter mais de 255 caracteres.',

            'matriculas_educandos.*.exists' => 'Uma das matrículas indicadas não existe.',
        ];
    }

}
