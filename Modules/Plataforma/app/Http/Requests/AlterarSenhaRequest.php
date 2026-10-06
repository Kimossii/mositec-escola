<?php

namespace Modules\Plataforma\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class AlterarSenhaRequest extends FormRequest
{
    /**
     * Auto-serviço: o próprio Super Admin autenticado (guard `plataforma`) altera a sua senha.
     */
    public function authorize(): bool
    {
        return $this->user('plataforma') !== null;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:plataforma'],
            'password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()->symbols(), 'confirmed', 'different:current_password'],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'Indique a senha actual.',
            'current_password.current_password' => 'A senha actual não está correcta.',
            'password.required' => 'Indique a nova senha.',
            'password.confirmed' => 'A confirmação da nova senha não coincide.',
            'password.different' => 'A nova senha tem de ser diferente da senha actual.',
            'password.min' => 'A nova senha deve ter pelo menos 12 caracteres.',
            'password.mixed' => 'A nova senha deve ter maiúsculas e minúsculas.',
            'password.numbers' => 'A nova senha deve ter pelo menos um número.',
            'password.symbols' => 'A nova senha deve ter pelo menos um símbolo.',
        ];
    }
}
