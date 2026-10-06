<?php

namespace Modules\Plataforma\Http\Requests;

/**
 * Só o formato do e-mail (opcional: obrigatório só quando a escola tem vários administradores, o que
 * o contrato decide). Se existe ou é de outra escola também não se sabe aqui.
 */
class RecuperarAdministradorRequest extends PedidoDeEscolaRequest
{
    public function rules(): array
    {
        return [
            'email' => ['nullable', 'string', 'email', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'email' => 'Indique um e-mail válido.',
        ];
    }
}
