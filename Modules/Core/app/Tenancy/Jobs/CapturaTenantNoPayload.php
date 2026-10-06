<?php

namespace Modules\Core\Tenancy\Jobs;

use Illuminate\Events\CallQueuedListener;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Queue\Queue;
use LogicException;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\TenantContext;

/**
 * Gancho de payload da fila: para jobs, listeners, mailables e notifications com ComTenant
 * acrescenta `tenant_id` e entrega a execução ao ExecutorDeJobsDeTenant. Falha o despacho se
 * não houver tenant corrente. Closures em fila são proibidas (não há como marcá-las).
 */
class CapturaTenantNoPayload
{
    /**
     * Os ganchos de payload são estáticos; o Laravel limpa-os entre testes, por isso regista-se
     * a cada arranque da aplicação (uma vez, em produção). O contexto é resolvido pelo
     * container corrente a cada despacho.
     */
    public static function registar(): void
    {
        Queue::createPayloadUsing(self::acrescentar(...));
    }

    /** @return array<string, mixed> */
    public static function acrescentar(string $ligacao, ?string $fila, array $payload): array
    {
        // Neste ponto o Laravel ainda guarda o próprio objecto em commandName.
        $comando = $payload['data']['commandName'] ?? null;

        if ($comando instanceof CallQueuedClosure) {
            throw new LogicException('Closures em fila não são suportadas: não transportam o tenant. Use um Job com a trait ComTenant.');
        }

        // Listeners, mailables e notifications em fila viajam embrulhados: decide o objecto real.
        $alvo = match (true) {
            $comando instanceof CallQueuedListener => $comando->class,
            $comando instanceof SendQueuedMailable => $comando->mailable,
            $comando instanceof SendQueuedNotifications => $comando->notification,
            default => $comando,
        };
        $classe = is_object($alvo) ? $alvo::class : $alvo;

        if (! is_string($classe) || ! class_exists($classe) || ! in_array(ComTenant::class, class_uses_recursive($classe), true)) {
            return [];
        }

        $contexto = app(TenantContext::class);

        if (! $contexto->temTenant()) {
            throw new TenantNaoResolvido(
                "Despacho de {$classe} (ComTenant) sem tenant corrente. dispatchAfterResponse() e o driver de fila \"background\" "
                . 'não são suportados: o contexto já foi limpo ou não existe no processo que cria o payload.',
            );
        }

        return [
            'job' => ExecutorDeJobsDeTenant::class . '@call',
            'tenant_id' => $contexto->id(),
        ];
    }
}
