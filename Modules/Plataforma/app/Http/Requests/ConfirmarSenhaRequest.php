<?php

namespace Modules\Plataforma\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Pede a senha actual do super admin autenticado (iniciar e desactivar o 2FA). */
class ConfirmarSenhaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('plataforma') !== null;
    }

    public function rules(): array
    {
        return ['password' => ['required', 'string', 'current_password:plataforma']];
    }

    public function messages(): array
    {
        return [
            'password.required' => 'Indique a senha actual.',
            'password.current_password' => 'A senha não está correcta.',
        ];
    }
}
