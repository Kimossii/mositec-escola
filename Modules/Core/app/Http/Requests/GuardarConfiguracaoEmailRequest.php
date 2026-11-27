<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Comunicacao\Email\EncriptacaoSmtp;
use Modules\Core\Comunicacao\Email\ValidadorDeServidorSmtp;

class GuardarConfiguracaoEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // `can:configuracao-email.editar` na rota
    }

    public function rules(): array
    {
        return [
            'servidor' => ['required', 'string', 'max:255'],
            'porta' => ['required', 'integer', Rule::in(ValidadorDeServidorSmtp::PORTAS)],
            'encriptacao' => ['required', Rule::in(array_map(fn (EncriptacaoSmtp $e) => $e->value, EncriptacaoSmtp::cases()))],
            'utilizador' => ['nullable', 'string', 'max:255'],
            'senha' => ['nullable', 'string', 'max:255'],
            'validar_certificado' => ['required', 'boolean'],
            'dominio_ehlo' => ['nullable', 'string', 'max:255'],
            'tempo_limite' => ['required', 'integer', 'between:5,30'],
            'remetente_nome' => ['nullable', 'string', 'max:255'],
            'remetente_endereco' => ['required', 'email', 'max:255'],
            'responder_para' => ['nullable', 'email', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'porta.in' => 'A porta tem de ser uma de: '.implode(', ', ValidadorDeServidorSmtp::PORTAS).'.',
            'tempo_limite.between' => 'O tempo limite tem de estar entre 5 e 30 segundos.',
        ];
    }
}
