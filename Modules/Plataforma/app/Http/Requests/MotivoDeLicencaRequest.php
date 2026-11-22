<?php

namespace Modules\Plataforma\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pedido sobre uma licença existente (`{licenca}`): exige sempre um motivo (fica no histórico e na auditoria).
 * Só o formato; o tecto exacto é do CicloDeVidaDaLicenca. Um pedido inválido volta ao detalhe da licença.
 */
class MotivoDeLicencaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'max:255'],
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

    protected function getRedirectUrl(): string
    {
        return route('plataforma.licencas.show', $this->route('licenca'));
    }
}
