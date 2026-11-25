<?php

namespace Modules\Plataforma\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CodigoDoisFactoresRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('plataforma') !== null;
    }

    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:20']];
    }

    public function messages(): array
    {
        return ['code.required' => 'Indique o código da aplicação.'];
    }
}
