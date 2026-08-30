<?php

namespace Modules\Autenticacao\Http\Requests;

use App\Http\Requests\BaseRequest;

class LoginRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'login' => 'required|string',
            'password' => 'required',
            'remember' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'login.required' => 'O email ou matrícula é obrigatório.',
            'password.required' => 'A senha é obrigatória.',
        ];
    }
}
