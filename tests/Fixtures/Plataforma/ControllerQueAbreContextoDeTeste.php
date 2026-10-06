<?php

namespace Tests\Fixtures\Plataforma;

use Closure;
use Modules\Core\Tenancy\TenantContext;
use Modules\Tenant\Models\Tenant;
use Throwable;

/**
 * Rota-fixture, só para a prova do espião do contexto (a "mutação" permanente): um controller que
 * faz o que a regra de ouro proíbe. Registada nos testes dentro do grupo `plataforma`.
 */
class ControllerQueAbreContextoDeTeste
{
    public function __invoke(string $operacao): string
    {
        $contexto = app(TenantContext::class);
        $tenant = Tenant::query()->firstOrFail()->paraTenantAtual();

        match ($operacao) {
            'definir' => $contexto->definir($tenant),
            'limpar' => $contexto->limpar(),
            'executarComo' => $contexto->executarComo($tenant, fn () => 1),
            'lembrar' => $contexto->lembrar('k', fn () => 1),
            // Violação escondida num catch (padrão de AuditaSemFalhar): o espião tem de a registar na mesma.
            'engolida' => $this->engolir(fn () => $contexto->executarComo($tenant, fn () => 1)),
            // Via tap(): o frame imediato é do vendor; o sítio da chamada continua a ser este ficheiro.
            'tap' => tap($contexto)->executarComo($tenant, fn () => 1),
        };

        return 'contexto-aberto';
    }

    private function engolir(Closure $fn): void
    {
        try {
            $fn();
        } catch (Throwable) {
            // engolida de propósito
        }
    }
}
