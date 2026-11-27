<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TestarConfiguracaoEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // `can:configuracao-email.editar` na rota
    }

    public function rules(): array
    {
        return ['destino' => ['required', 'email', 'max:255']];
    }
}
