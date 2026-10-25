<?php

namespace Modules\Core\Traits;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Core\Support\SanitizadorDeLogs;
use Modules\Core\Tenancy\TenantContext;
use Throwable;

/**
 * Logs para qualquer classe (serviços, actions, controllers, jobs): `$this->logInfo('Pagamento registado', ['id' => $id])`.
 *
 * Cada entrada leva `[Classe::método:linha]` (quem chamou e onde) e, sozinhos, o `tenant_id`, o `user_id` e o `pedido`
 * (identificador que liga todas as linhas do mesmo pedido). Em segurança e estabilidade:
 *  - o contexto passa por `SanitizadorDeLogs`: segredos escondidos, tamanho e profundidade limitados, objetos resumidos;
 *  - a mensagem é uma só linha de tamanho limitado;
 *  - escrever um log NUNCA lança excepção nem derruba a operação de negócio;
 *  - em desempenho, o `debug` só é escrito em modo debug e a pilha de chamadas só se lê quando a entrada vai mesmo ser escrita.
 */
trait LogService
{
    protected function logDebug(string $message, array $context = []): void
    {
        // Em produção (APP_DEBUG=false) não custa nada: nem sequer se constrói a mensagem.
        if (config('app.debug')) {
            $this->escreverLog('debug', $message, $context);
        }
    }

    protected function logInfo(string $message, array $context = []): void
    {
        $this->escreverLog('info', $message, $context);
    }

    protected function logNotice(string $message, array $context = []): void
    {
        $this->escreverLog('notice', $message, $context);
    }

    protected function logWarning(string $message, array $context = []): void
    {
        $this->escreverLog('warning', $message, $context);
    }

    protected function logError(string $message, array $context = []): void
    {
        $this->escreverLog('error', $message, $context);
    }

    protected function logCritical(string $message, array $context = []): void
    {
        $this->escreverLog('critical', $message, $context);
    }

    /** Regista uma excepção (classe, mensagem e local — sem a pilha inteira) e devolve-a, para `throw $this->logException($e)`. */
    protected function logException(Throwable $excecao, string $message = 'Excepção', array $context = []): Throwable
    {
        $this->escreverLog('error', $message, [...$context, 'excecao' => $excecao]);

        return $excecao;
    }

    private function escreverLog(string $nivel, string $message, array $context): void
    {
        try {
            Log::{$nivel}(
                $this->construirMensagemDeLog($message),
                [...$this->contextoAutomaticoDeLog(), ...SanitizadorDeLogs::contexto($context)],
            );
        } catch (Throwable) {
            // Um log falhado (disco cheio, canal indisponível, contexto estranho) nunca pode estragar a operação.
        }
    }

    private function construirMensagemDeLog(string $message): string
    {
        // Frames: 0 = esta função, 1 = escreverLog, 2 = logInfo/logError…, 3 = o método que chamou o log.
        $pilha = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);

        $classe = $pilha[3]['class'] ?? static::class;
        $metodo = $pilha[3]['function'] ?? 'desconhecido';
        $linha = $pilha[2]['line'] ?? '?';

        // Também a classe passa pelo filtro: o nome de uma classe anónima leva um byte NUL.
        return SanitizadorDeLogs::texto('['.$classe.'::'.$metodo.':'.$linha.'] '.$message, 1000);
    }

    /** @return array{tenant_id?: int, user_id?: int|string, pedido?: string} */
    private function contextoAutomaticoDeLog(): array
    {
        $extra = [];

        $tenant = app(TenantContext::class);
        if ($tenant->temTenant()) {
            $extra['tenant_id'] = $tenant->id();
        }

        if (($utilizador = auth()->id()) !== null) {
            $extra['user_id'] = $utilizador;
        }

        // Um identificador por pedido HTTP, criado à primeira linha de log: liga tudo o que aconteceu nesse pedido.
        if (! app()->runningInConsole() && app()->bound('request')) {
            $atributos = request()->attributes;
            $extra['pedido'] = $atributos->get('pedido_id') ?? tap(Str::random(8), fn (string $id) => $atributos->set('pedido_id', $id));
        }

        return $extra;
    }
}
