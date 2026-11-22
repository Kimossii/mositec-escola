<?php

namespace Modules\Licenciamento\Services;

use Illuminate\Contracts\Container\Container;
use Modules\Core\Licenciamento\Contracts\ContaRecursoLicenciado;
use Modules\Core\Licenciamento\Contracts\GarantiaDeLimites;
use Modules\Core\Licenciamento\Enums\Recurso;
use Modules\Core\Licenciamento\Exceptions\LimiteDeLicencaExcedido;
use Modules\Core\Tenancy\TenantContext;
use Modules\Licenciamento\Models\DireitosLocais;

/**
 * Aplica os limites da licença do tenant actual. Limites são sempre opcionais: sem flag, sem licença ou limite null
 * não faz nada. Tem de ser chamado DENTRO da transacção da Action que cria o recurso: bloqueia (FOR UPDATE) a linha
 * de direitos do tenant antes de contar, o que serializa criações concorrentes até ao commit.
 */
class LimitesDeLicenca implements GarantiaDeLimites
{
    public function __construct(
        private readonly DireitosService $direitos,
        private readonly TenantContext $contexto,
        private readonly Container $container,
    ) {}

    public function garantir(Recurso $recurso, int $adicionar = 1): void
    {
        if (! config('licenciamento.activo') || ! $this->contexto->temTenant()) {
            return;
        }

        $limite = $this->direitos->doTenantActual()?->limiteDe($recurso);

        if ($limite === null) {
            return;
        }

        // O bloqueio vem ANTES da contagem; a transacção da Action liberta-o no commit/rollback.
        DireitosLocais::query()->where('tenant_id', $this->contexto->id())->lockForUpdate()->value('id');

        if ($this->contar($recurso) + $adicionar > $limite) {
            throw LimiteDeLicencaExcedido::para($recurso, $limite);
        }
    }

    /** Utilização actual do recurso no tenant actual (soma dos contadores registados pelos módulos donos). */
    public function utilizacao(Recurso $recurso): int
    {
        return $this->contar($recurso);
    }

    private function contar(Recurso $recurso): int
    {
        $total = 0;

        foreach ($this->container->tagged(ContaRecursoLicenciado::TAG) as $contador) {
            if ($contador->recurso() === $recurso) {
                $total += $contador->contar();
            }
        }

        return $total;
    }
}
