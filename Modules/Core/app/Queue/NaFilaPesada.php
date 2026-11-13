<?php

namespace Modules\Core\Queue;

/**
 * Marca um Job de trabalho longo (exportações, importações, rotinas diárias de propinas e multas). Vai para a
 * ligação `queue.ligacao_pesada` (`database_pesado`, fila `pesado`, `retry_after` acima dos timeouts dos jobs), de modo
 * que nunca atrasa os jobs rápidos da ligação padrão (notificações, feedbacks). Em `QUEUE_CONNECTION=sync` a
 * ligação pesada também é `sync` e o job corre no próprio pedido.
 *
 * Uso: `use Queueable, NaFilaPesada;` e `$this->naFilaPesada();` no construtor.
 */
trait NaFilaPesada
{
    protected function naFilaPesada(): void
    {
        $ligacao = (string) config('queue.ligacao_pesada');

        $this->onConnection($ligacao);

        if ($ligacao !== 'sync') {
            $this->onQueue((string) config("queue.connections.{$ligacao}.queue", 'pesado'));
        }
    }
}
