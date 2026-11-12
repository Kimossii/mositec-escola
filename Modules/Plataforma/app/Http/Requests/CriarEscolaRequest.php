<?php

namespace Modules\Plataforma\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Só o formato do formulário. As regras de domínio (formato, reservados, host central, duplicados),
 * de código (formato e unicidade) e de e-mail pertencem à CriarTenantAction e chegam aos campos
 * como erros de validação: aqui não se copiam.
 */
class CriarEscolaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Código em branco = gerado pela Action.
        if (is_string($this->input('codigo')) && trim($this->input('codigo')) === '') {
            $this->merge(['codigo' => null]);
        }

        // Instalação dedicada (modo único): o domínio não se pede nem se aceita; a Action usa o host da aplicação.
        if (config('tenancy.modo') === 'unico') {
            $this->merge(['dominio' => null]);
        } elseif (is_string($this->input('dominio')) && trim($this->input('dominio')) === '') {
            $this->merge(['dominio' => null]);
        }
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'admin_nome' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'string', 'max:255'],
            // Obrigatório no modo `dominio`; no modo `unico` o campo não existe (ver prepareForValidation).
            'dominio' => config('tenancy.modo') === 'unico' ? ['nullable'] : ['required', 'string', 'max:253'],
            'codigo' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Este campo é obrigatório.',
            'string' => 'Valor inválido.',
            'max' => 'Demasiado longo (máximo :max caracteres).',
        ];
    }
}
