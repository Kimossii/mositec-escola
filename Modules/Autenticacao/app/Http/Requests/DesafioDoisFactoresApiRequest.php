<?php

namespace Modules\Autenticacao\Http\Requests;

use App\Http\Requests\BaseRequest;

class DesafioDoisFactoresApiRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'desafio' => ['required', 'string', 'size:40'],
            'code' => ['nullable', 'required_without:recovery_code', 'string', 'max:10'],
            'recovery_code' => ['nullable', 'required_without:code', 'string', 'max:64'],
        ];
    }

    public function messages(): array
    {
        return [
            'desafio.required' => 'O desafio é obrigatório.',
            'desafio.size' => 'Desafio inválido.',
            'code.required_without' => 'Indique o código da aplicação ou um código de recuperação.',
            'recovery_code.required_without' => 'Indique o código da aplicação ou um código de recuperação.',
        ];
    }
}
