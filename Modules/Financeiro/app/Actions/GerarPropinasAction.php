<?php

namespace Modules\Financeiro\Actions;

use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
use Modules\Financeiro\DTO\ResultadoGeracaoPropinasDTO;
use Modules\Financeiro\Services\GerarPropinasService;
use Modules\Core\Traits\LogService;

/**
 * Geração manual confirmada: recalcula tudo sobre o estado actual, matrícula a matrícula, numa
 * transacção por matrícula (a pré-visualização é só informativa e nunca é reutilizada, D4).
 */
class GerarPropinasAction
{
    use LogService;

    public function __construct(private GerarPropinasService $geracao)
    {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(PedidoGeracaoPropinasDTO $pedido): ResultadoGeracaoPropinasDTO
    {
        return $this->comLog('Gerar propinas', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($pedido));
    }

    private function executarSemRegisto(PedidoGeracaoPropinasDTO $pedido): ResultadoGeracaoPropinasDTO
    {
        return $this->geracao->gerar($pedido);
    }
}
