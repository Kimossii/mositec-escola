<?php

namespace Modules\Core\Tenancy\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Support\Facades\Log;
use Modules\Core\Tenancy\Contracts\CatalogoDeTenants;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Core\Tenancy\TenantContext;
use RuntimeException;
use Throwable;

/**
 * Executa jobs (e listeners, mailables e notifications em fila) com ComTenant dentro do tenant
 * que os despachou (ver ComTenant). Tudo o que o CallQueuedHandler faz (desserializar, middleware,
 * handle, continuar a cadeia, failed) acontece dentro de TenantContext::executarComo(), que repõe
 * contexto e memória anteriores no fim, mesmo com excepção.
 */
class ExecutorDeJobsDeTenant extends CallQueuedHandler
{
    public function call(Job $job, array $data)
    {
        $id = (int) ($job->payload()['tenant_id'] ?? 0);
        $tenant = app(CatalogoDeTenants::class)->porId($id);

        if ($tenant === null || $tenant->estado !== EstadoTenant::ACTIVO) {
            $this->avisar('Job descartado: o tenant não está activo.', $id, $job, $data, $tenant);
            $this->descartar($job, $data, $tenant);

            return;
        }

        app(TenantContext::class)->executarComo($tenant, fn () => parent::call($job, $data));
    }

    public function failed(array $data, $e, string $uuid, ?Job $job = null)
    {
        if ($job === null) {
            return;
        }

        $id = (int) ($job->payload()['tenant_id'] ?? 0);
        $tenant = app(CatalogoDeTenants::class)->porId($id);

        // Sem tenant em serviço não há contexto seguro para o failed() do job.
        if ($tenant === null || $tenant->estado !== EstadoTenant::ACTIVO) {
            $this->avisar('Callback failed() do job ignorado: o tenant não está activo.', $id, $job, $data, $tenant);

            return;
        }

        app(TenantContext::class)->executarComo($tenant, fn () => parent::failed($data, $e, $uuid, $job));
    }

    /**
     * Descartar não é um sucesso silencioso: liberta o lock de ShouldBeUnique e, num lote, conta o
     * job como falhado (sem allowFailures isto cancela o lote). Tenant suspenso/encerrado: a
     * desserialização corre no tenant, para restaurar models. Tenant inexistente ou erro ao
     * desserializar: só se apaga o job.
     */
    private function descartar(Job $job, array $data, ?TenantAtual $tenant): void
    {
        try {
            $limpar = function () use ($job, $data) {
                $comando = $this->setJobInstanceIfNecessary($job, $this->getCommand($data));

                $this->ensureUniqueJobLockIsReleased($comando);

                if (in_array(Batchable::class, class_uses_recursive($comando), true)) {
                    $comando->batch()?->recordFailedJob(
                        $job->uuid() ?? '',
                        new RuntimeException('Job descartado: o tenant não está activo.'),
                    );
                }
            };

            $tenant === null ? $limpar() : app(TenantContext::class)->executarComo($tenant, $limpar);
        } catch (Throwable $e) {
            Log::warning('Não foi possível libertar o lock/lote de um job descartado.', ['job' => $data['commandName'] ?? null, 'excepcao' => $e::class]);
        }

        $job->delete();
    }

    /** Só ids e nomes de classe: nunca o payload do job. */
    private function avisar(string $mensagem, int $id, Job $job, array $data, ?TenantAtual $tenant): void
    {
        Log::warning($mensagem, [
            'tenant_id' => $id,
            'job' => $data['commandName'] ?? $job->resolveName(),
            'motivo' => $tenant === null ? 'inexistente' : strtolower($tenant->estado->name),
        ]);
    }
}
