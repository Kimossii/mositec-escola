<?php

namespace Modules\Autenticacao\Console;

use Illuminate\Console\Command;
use Modules\Autenticacao\Actions\RecuperarAdministradorAction;
use Modules\Autenticacao\Exceptions\AdministradorNaoRecuperavel;
use Modules\Core\Tenancy\Console\EscolheUmTenant;
use Modules\Core\Tenancy\TenantContext;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Recupera o acesso do administrador de UM tenant (sem --todos). Mostra a nova senha
 * temporária uma única vez e nunca a regista. Sem lógica: delega na Action.
 */
class RecuperarAdministradorCommand extends Command
{
    use EscolheUmTenant;

    protected $signature = 'mosi:tenant:admin:reset
        {--email= : Email do administrador (obrigatório se houver mais de um)}';

    protected $description = 'Gera nova senha temporária para o administrador de uma escola (--tenant=CODIGO)';

    public function handle(TenantContext $contexto, RecuperarAdministradorAction $action): int
    {
        $tenant = $this->tenantEscolhido();

        if ($tenant === null) {
            return self::FAILURE;
        }

        try {
            $credencial = $contexto->executarComo($tenant, fn () => $action->executar($this->option('email')));
        } catch (AdministradorNaoRecuperavel $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            // Sem getMessage(): numa QueryException traria SQL e bindings.
            report($e);
            $this->error('Falha inesperada (' . $e::class . '); nada foi alterado. Consulte os logs.');

            return self::FAILURE;
        }

        $this->info("Acesso recuperado em {$tenant->codigo}.");
        $this->line("Administrador:  {$credencial->email}");
        // RAW: a senha pode conter \ < > / que o formatador do Symfony trataria como marcação.
        $this->output->writeln('Senha temporária: ' . $credencial->senha(), OutputInterface::OUTPUT_RAW);
        $this->warn('A senha é mostrada uma única vez; será exigida a troca no próximo acesso. As sessões anteriores foram terminadas.');

        return self::SUCCESS;
    }
}
