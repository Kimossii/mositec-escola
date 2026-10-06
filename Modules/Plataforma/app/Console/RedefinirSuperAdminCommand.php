<?php

namespace Modules\Plataforma\Console;

use Illuminate\Console\Command;
use Modules\Plataforma\Actions\RedefinirSuperAdminAction;
use Modules\Plataforma\Exceptions\SuperAdminNaoRedefinivel;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Gera nova senha temporária para um super admin. Mostra-a uma única vez e nunca a regista.
 * Sem lógica: delega na Action. Não opera sobre dados de escola.
 */
class RedefinirSuperAdminCommand extends Command
{
    protected $signature = 'mosi:plataforma:admin:reset
        {--email= : Email do super admin}';

    protected $description = 'Gera nova senha temporária para um super admin da Plataforma MosiTec';

    public function handle(RedefinirSuperAdminAction $action): int
    {
        $email = trim((string) $this->option('email'));
        $email = $email !== '' ? $email : trim((string) $this->ask('Email do super admin'));

        try {
            $credencial = $action->executar($email);
        } catch (SuperAdminNaoRedefinivel $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            // Sem getMessage(): numa QueryException traria SQL e bindings.
            report($e);
            $this->error('Falha inesperada (' . $e::class . '); nada foi alterado. Consulte os logs.');

            return self::FAILURE;
        }

        $this->info('Acesso recuperado.');
        $this->line("Super admin:    {$credencial->email}");
        // RAW: a senha pode conter \ < > / que o formatador do Symfony trataria como marcação.
        $this->output->writeln('Senha temporária: ' . $credencial->senha(), OutputInterface::OUTPUT_RAW);
        $this->warn('A senha é mostrada uma única vez; será exigida a troca no próximo acesso.');

        return self::SUCCESS;
    }
}
