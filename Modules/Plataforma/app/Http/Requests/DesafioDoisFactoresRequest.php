<?php

namespace Modules\Plataforma\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Passo 2 do login: `code` (aplicação) ou `recovery_code`. A sessão pendente é validada no controller. */
class DesafioDoisFactoresRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:20', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'max:50', 'required_without:code'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required_without' => 'Indique o código.',
            'recovery_code.required_without' => 'Indique o código de recuperação.',
        ];
    }
}
