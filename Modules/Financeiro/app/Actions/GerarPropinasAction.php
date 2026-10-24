<?php

namespace Modules\Financeiro\Actions;

use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
use Modules\Financeiro\DTO\ResultadoGeracaoPropinasDTO;
use Modules\Financeiro\Services\GerarPropinasService;

/**
 * Geração manual confirmada: recalcula tudo sobre o estado actual, matrícula a matrícula, numa
 * transacção por matrícula (a pré-visualização é só informativa e nunca é reutilizada, D4).
 */
class GerarPropinasAction
{
    public function __construct(private GerarPropinasService $geracao)
    {
    }

    public function executar(PedidoGeracaoPropinasDTO $pedido): ResultadoGeracaoPropinasDTO
    {
        return $this->geracao->gerar($pedido);
    }
}
