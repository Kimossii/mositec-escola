<?php

namespace Modules\Plataforma\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'O e-mail é obrigatório.',
            'email.email' => 'Indique um e-mail válido.',
            'email.string' => 'Indique um e-mail válido.',
            'password.required' => 'A senha é obrigatória.',
            'password.string' => 'A senha é obrigatória.',
        ];
    }
}
