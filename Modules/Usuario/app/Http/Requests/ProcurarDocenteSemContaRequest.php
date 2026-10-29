<?php

namespace Modules\Usuario\Http\Requests;

use App\Http\Requests\BaseRequest;

class ProcurarDocenteSemContaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        // A permissão (usuario.criar) e o throttle estão na rota.
        return true;
    }

    public function rules(): array
    {
        return [
            'pesquisa' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'pesquisa.string' => 'A pesquisa deve ser um texto válido.',
            'pesquisa.max' => 'A pesquisa não pode ter mais de 100 caracteres.',
        ];
    }
}
