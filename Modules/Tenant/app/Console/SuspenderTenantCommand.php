<?php

namespace Modules\Tenant\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Contracts\RevogaAcessosDoTenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Tenant\Actions\SuspenderTenantAction;
use Modules\Tenant\Exceptions\DadosDeTenantInvalidos;
use Modules\Tenant\Exceptions\OperacaoDeTenantRecusada;
use Modules\Tenant\Services\TenantConsultaService;

/** Recolhe o código e o motivo e chama SuspenderTenantAction; com --revogar-sessoes termina também os acessos. Sem lógica. */
class SuspenderTenantCommand extends Command
{
    protected $signature = 'mosi:tenant:suspend
        {codigo : Código do tenant (MOSI-000000)}
        {--motivo= : Motivo da suspensão (obrigatório)}
        {--revogar-sessoes : Apaga também as sessões e os tokens de API dos utilizadores da escola}';

    protected $description = 'Suspende uma escola (tenant): deixa de ser acessível, os dados ficam intactos';

    public function handle(TenantConsultaService $consulta, SuspenderTenantAction $action, TenantContext $contexto): int
    {
        try {
            $tenant = $consulta->porCodigo((string) $this->argument('codigo'));
            $motivo = trim((string) $this->option('motivo'));
            $motivo = $motivo !== '' ? $motivo : trim((string) $this->ask('Motivo da suspensão'));

            $action->executar($tenant, $motivo);
        } catch (DadosDeTenantInvalidos $e) {
            foreach ($e->erros as $erro) {
                $this->error($erro);
            }

            return self::FAILURE;
        } catch (OperacaoDeTenantRecusada $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Tenant {$tenant->codigo} suspenso.");

        if ($this->option('revogar-sessoes')) {
            $revogados = $contexto->executarComo($tenant->paraTenantAtual(), fn () => app(RevogaAcessosDoTenant::class)->revogar());

            $this->info("{$revogados['sessoes']} sessão(ões) e {$revogados['tokens']} token(s) revogado(s).");
        }

        return self::SUCCESS;
    }
}
