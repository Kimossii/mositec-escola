<?php

namespace Modules\Financeiro\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Console\ParaTodosOsTenants;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Permissao\Actions\SincronizarPerfisDeSistemaAction;

/**
 * Põe tenants existentes em dia com o módulo Financeiro: cria as regras de cobrança e a
 * configuração monetária em falta e concede aos perfis de sistema as permissões novas. Idempotente.
 * Pré-requisito: `php artisan db:seed --force` (catálogo global de módulos e acções).
 */
class SincronizarFinanceiroCommand extends Command
{
    use ParaTodosOsTenants;

    protected $signature = 'financeiro:sincronizar';

    protected $description = 'Cria as regras de cobrança, a configuração monetária e as permissões do Financeiro nos tenants existentes';

    public function handle(SincronizarPerfisDeSistemaAction $perfis): int
    {
        return $this->paraTenants(function (TenantAtual $tenant) use ($perfis): void {
            RegraCobranca::doTenant();
            ConfiguracaoMonetaria::doTenant();
            $perfis->executar();
        });
    }
}
