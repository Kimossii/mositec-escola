<?php

namespace Modules\Core\Tenancy\Jobs;

/**
 * Marca um Job (ShouldQueue) que toca em dados de tenant (spec §13). Uso:
 *
 *     class EnviarAvisoJob implements ShouldQueue
 *     {
 *         use Queueable, ComTenant;
 *
 *         public function handle(): void { ... }   // handle() normal, já com contexto
 *     }
 *
 * - Ao despachar (e ao construir uma cadeia ou um lote) o id do tenant corrente vai no payload,
 *   ao lado do job serializado; nunca o objecto Tenant. Sem contexto, o despacho lança
 *   TenantNaoResolvido e nada é enfileirado. Ver CapturaTenantNoPayload.
 * - Ao executar, o job é entregue ao ExecutorDeJobsDeTenant, que reentra com
 *   TenantContext::executarComo() antes de o desserializar (os models com scope guardados no
 *   job são restaurados já dentro do tenant), corre middleware e handle() e repõe o contexto
 *   anterior no fim, mesmo com excepção. Tenant inexistente, suspenso ou encerrado: o job é
 *   descartado (não falha nem repete) e fica um aviso no log.
 * - Cada job de uma cadeia (Bus::chain / ->chain()) ou de um lote precisa da trait: a
 *   continuação é despachada dentro do contexto do tenant do elo anterior.
 * - Um job SEM a trait que toque em models com scope falha com TenantNaoResolvido (pretendido).
 * - A trait também vale para listeners, mailables e notifications em fila (o gancho desembrulha
 *   CallQueuedListener, SendQueuedMailable e SendQueuedNotifications). Closures em fila são
 *   proibidas (LogicException no despacho): use um Job.
 *
 * Regras para jobs e comandos
 * - NÃO suportado (falha alto com TenantNaoResolvido no despacho): dispatchAfterResponse() (o
 *   ResolverTenant já limpou o contexto quando a aplicação termina) e o driver de fila "background"
 *   (o payload é criado num processo filho, sem contexto).
 * - `dispatch()` devolve um PendingDispatch que só despacha ao ser destruído: não o devolva de um
 *   closure passado a executarComo(); trate-o dentro do contexto.
 * - ShouldBeUnique: o lock do Laravel não inclui o tenant. Use também a trait UnicoPorTenant (o
 *   uniqueId() passa a levar o id do tenant); o teste de arquitectura recusa ShouldBeUnique + ComTenant sem ela.
 * - Com a fila "sync" o job corre dentro do contexto de quem despacha (executarComo repõe-o no
 *   fim); com um worker não há contexto prévio, e o Laravel repõe as instâncias scoped entre jobs.
 * - Descarte (tenant inexistente/suspenso/encerrado): liberta o lock unique e, num lote, conta o job
 *   como falhado (sem allowFailures, cancela o lote). O failed() do job não corre.
 * - Comandos de dados de escola usam ParaTodosOsTenants/EscolheUmTenant. Agendamentos:
 *   `Schedule::command('x --todos')`. O worker (queue:work) é central, um só para todos os tenants.
 */
trait ComTenant {}
