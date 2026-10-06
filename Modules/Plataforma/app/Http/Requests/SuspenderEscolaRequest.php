<?php

namespace Modules\Plataforma\Http\Requests;

/**
 * Só o formato: o motivo é texto e tem um tecto de tamanho. A obrigatoriedade real e o limite exacto
 * do motivo pertencem à SuspenderTenantAction.
 */
class SuspenderEscolaRequest extends PedidoDeEscolaRequest
{
    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'max:2000'],
            'revogar_acessos' => ['nullable', 'boolean'],
        ];
    }
}
