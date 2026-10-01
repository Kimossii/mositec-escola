<?php

namespace Modules\Autenticacao\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class AlterarSenhaRequest extends FormRequest
{
    /**
     * Auto-serviço: o próprio utilizador autenticado (rota no grupo `auth`) altera a sua senha.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', Password::default(), 'confirmed', 'different:current_password'],
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
        ];
    }
}
