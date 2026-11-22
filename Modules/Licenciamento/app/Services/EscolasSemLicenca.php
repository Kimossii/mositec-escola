<?php

namespace Modules\Licenciamento\Services;

use Carbon\CarbonImmutable;
use Modules\Core\Tenancy\Contracts\CatalogoDeTenants;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Core\Tenancy\TenantContext;
use Modules\Licenciamento\Enums\ModoDeOperacao;

/**
 * Só leitura. Diz que escolas ficariam BLOQUEADAS se o licenciamento estivesse activo (sem licença, revogada, ou expirada
 * para além da tolerância), independentemente do valor actual da flag: é a verificação prévia à activação.
 */
class EscolasSemLicenca
{
    public function __construct(
        private readonly CatalogoDeTenants $tenants,
        private readonly TenantContext $contexto,
        private readonly DireitosService $direitos,
    ) {}

    /**
     * @param  list<string>|null  $codigos  restringe às escolas indicadas (null = todas)
     * @return list<array{tenant: TenantAtual, motivo: string}>
     */
    public function listar(?array $codigos = null): array
    {
        $agora = CarbonImmutable::now('UTC');
        $filtro = $codigos === null ? null : array_map(fn (string $c) => strtoupper(trim($c)), $codigos);
        $lista = [];

        foreach ($this->tenants->todos() as $tenant) {
            if ($filtro !== null && ! in_array(strtoupper($tenant->codigo), $filtro, true)) {
                continue;
            }

            $motivo = $this->contexto->executarComo($tenant, function () use ($agora): ?string {
                $direitos = $this->direitos->doTenantActual();
                $revogada = $this->direitos->revogadaDoTenantActual();
                $modo = AvaliadorDeLicenca::modo($direitos, $agora, (int) config('licenciamento.aviso_dias_padrao', 30), $revogada);

                if ($modo !== ModoDeOperacao::BLOQUEADO) {
                    return null;
                }

                return match (true) {
                    $revogada => 'licença revogada',
                    $direitos === null => 'sem licença',
                    default => 'licença expirada (tolerância terminada)',
                };
            });

            if ($motivo !== null) {
                $lista[] = ['tenant' => $tenant, 'motivo' => $motivo];
            }
        }

        return $lista;
    }
}
