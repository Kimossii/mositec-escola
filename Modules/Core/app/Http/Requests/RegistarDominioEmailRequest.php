<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegistarDominioEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // `can:configuracao-email.editar` na rota
    }

    public function rules(): array
    {
        return [
            'dominio' => ['required', 'string', 'max:253'],
            'remetente_nome' => ['nullable', 'string', 'max:120'],
            'remetente_local' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function messages(): array
    {
        return ['required' => 'Este campo é obrigatório.', 'max' => 'Valor demasiado longo.', 'string' => 'Valor inválido.'];
    }
}
