<?php

namespace Modules\Docente\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;

/**
 * «Criar acesso» de um docente (decisão E6). Do cliente só o email de login e a senha: nome e pessoa vêm do docente,
 * por isso nada mais está nas regras (nunca chega a validated()). Exige `docente.editar` e `usuario.criar` (E11).
 */
class CriarAcessoDocenteRequest extends BaseRequest
{
    public function authorize(): bool
    {
        $utilizador = $this->user();

        return $utilizador !== null && $utilizador->can('docente.editar') && $utilizador->can('usuario.criar');
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'min:6', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'O email de acesso é obrigatório.',
            'email.email' => 'Informe um email válido.',
            'email.max' => 'O email não pode ter mais de 255 caracteres.',
            'email.unique' => 'Este email já está em uso.',
            'password.required' => 'A senha é obrigatória.',
            'password.min' => 'A senha deve ter pelo menos 6 caracteres.',
            'password.confirmed' => 'A confirmação da senha não coincide.',
        ];
    }
}
