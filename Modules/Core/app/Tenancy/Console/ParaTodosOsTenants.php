<?php

namespace Modules\Core\Tenancy\Console;

use Closure;
use Modules\Core\Tenancy\Contracts\CatalogoDeTenants;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Core\Tenancy\TenantContext;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

/**
 * Convenção dos comandos Artisan que operam sobre dados de escola (spec §13): acrescenta
 * `--tenant=CODIGO` (um) e `--todos` (todos os ACTIVOS). Exactamente uma é obrigatória:
 * nunca há "todos" por omissão.
 *
 * Uso: `use ParaTodosOsTenants;` e, no handle(), `return $this->paraTenants(fn (TenantAtual $t) => ...);`.
 * Cada tenant corre em TenantContext::executarComo(); uma falha num tenant é registada e não
 * impede os seguintes, mas o comando acaba com código de saída diferente de zero.
 * Tenants suspensos ou encerrados são saltados com aviso (com --tenant explícito, é erro).
 *
 * O valor devolvido por $fn é descartado dentro do contexto: um PendingDispatch devolvido
 * (`fn () => Job::dispatch()`) só despacha ao ser destruído, e isso tem de acontecer ainda
 * dentro do tenant. O mesmo risco existe em qualquer chamada directa a executarComo().
 */
trait ParaTodosOsTenants
{
    use EscolheUmTenant {
        configure as private configurarOpcaoTenant;
    }

    protected function configure(): void
    {
        $this->configurarOpcaoTenant();

        $this->addOption('todos', null, InputOption::VALUE_NONE, 'Todos os tenants activos');
    }

    /**
     * @param  Closure(TenantAtual): mixed  $fn
     */
    protected function paraTenants(Closure $fn): int
    {
        $umCodigo = trim((string) $this->option('tenant')) !== '';
        $todos = (bool) $this->option('todos');

        if ($umCodigo === $todos) {
            $this->error($todos ? 'Use --tenant=CODIGO ou --todos, não ambos.' : 'Indique --tenant=CODIGO ou --todos: não há tenant por omissão.');

            return SymfonyCommand::FAILURE;
        }

        if ($umCodigo) {
            $tenant = $this->tenantEscolhido();

            $alvos = $tenant === null ? null : [$tenant];
        } else {
            [$alvos, $saltados] = $this->todosActivos();
        }

        if ($alvos === null) {
            return SymfonyCommand::FAILURE;
        }

        $contexto = app(TenantContext::class);
        $falhas = 0;
        $saltados ??= 0;

        foreach ($alvos as $tenant) {
            try {
                $contexto->executarComo($tenant, function (TenantAtual $tenant) use ($fn): void {
                    $fn($tenant);
                });
                $this->line("[{$tenant->codigo}] concluído.");
            } catch (Throwable $e) {
                $falhas++;
                report($e);
                // Sem getMessage(): numa QueryException traria SQL e bindings.
                $this->error("[{$tenant->codigo}] falhou (" . $e::class . "); consulte os logs.");
            }
        }

        $this->line(count($alvos) - $falhas . ' processados, ' . $saltados . ' saltados.');

        if ($falhas > 0) {
            $this->error("{$falhas} tenant(s) falharam de " . count($alvos) . '.');

            return SymfonyCommand::FAILURE;
        }

        return SymfonyCommand::SUCCESS;
    }

    /** @return array{0: list<TenantAtual>, 1: int} activos e número de saltados */
    private function todosActivos(): array
    {
        $activos = [];
        $saltados = 0;

        foreach (app(CatalogoDeTenants::class)->todos() as $tenant) {
            if ($tenant->estado === EstadoTenant::ACTIVO) {
                $activos[] = $tenant;

                continue;
            }

            $saltados++;
            $this->warn("[{$tenant->codigo}] saltado: tenant " . strtolower($tenant->estado->label()) . '.');
        }

        if ($saltados > 0) {
            $this->warn("{$saltados} tenant(s) não activos saltados.");
        }

        return [$activos, $saltados];
    }
}
