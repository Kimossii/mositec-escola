<?php

namespace Modules\Autenticacao\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RecuperarSenhaRequest extends FormRequest
{
    /** Pública: a autorização é a ligação assinada (middleware `signed`) e a revalidação na Action. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', Password::default(), 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.required' => 'Indique a nova senha.',
            'password.confirmed' => 'A confirmação da nova senha não coincide.',
        ];
    }
}
