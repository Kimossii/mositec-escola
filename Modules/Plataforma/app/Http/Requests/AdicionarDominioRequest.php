<?php

namespace Modules\Plataforma\Http\Requests;

/**
 * Só o formato: texto não vazio e com tecto de tamanho. Tudo o resto (formato do domínio, reservados,
 * hosts centrais, duplicados) é decidido pela regra de domínio do módulo Tenant, através da Action.
 */
class AdicionarDominioRequest extends PedidoDeEscolaRequest
{
    public function rules(): array
    {
        return [
            'dominio' => ['required', 'string', 'max:253'],
        ];
    }
}
