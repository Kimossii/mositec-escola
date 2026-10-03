<?php

namespace Modules\Plataforma\Console;

use Illuminate\Console\Command;
use Modules\Plataforma\Actions\CriarSuperAdminAction;
use Modules\Plataforma\Exceptions\DadosDeSuperAdminInvalidos;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Recolhe nome e e-mail (opções ou perguntas) e chama CriarSuperAdminAction. Sem lógica.
 * Não aceita palavra-passe: a do super admin é temporária e mostrada uma única vez.
 * Não opera sobre dados de escola, por isso não usa a convenção --tenant/--todos.
 */
class CriarSuperAdminCommand extends Command
{
    protected $signature = 'mosi:plataforma:admin:create
        {--nome= : Nome do super admin}
        {--email= : Email do super admin}';

    protected $description = 'Cria um super admin da Plataforma MosiTec, com senha temporária';

    public function handle(CriarSuperAdminAction $action): int
    {
        $nome = $this->valor('nome', 'Nome do super admin');
        $email = $this->valor('email', 'Email do super admin');

        try {
            $credencial = $action->executar($nome, $email);
        } catch (DadosDeSuperAdminInvalidos $e) {
            foreach ($e->erros as $erro) {
                $this->error($erro);
            }

            return self::FAILURE;
        } catch (Throwable $e) {
            // Sem getMessage(): numa QueryException traria SQL e bindings.
            report($e);
            $this->error('Falha inesperada (' . $e::class . '); nada foi criado. Consulte os logs.');

            return self::FAILURE;
        }

        $this->info('Super admin criado.');
        $this->line("Super admin:    {$credencial->email}");
        // RAW: a senha pode conter \ < > / que o formatador do Symfony trataria como marcação.
        $this->output->writeln('Senha temporária: ' . $credencial->senha(), OutputInterface::OUTPUT_RAW);
        $this->warn('A senha é mostrada uma única vez; será exigida a troca no primeiro acesso.');

        return self::SUCCESS;
    }

    private function valor(string $opcao, string $pergunta): string
    {
        $valor = trim((string) $this->option($opcao));

        return $valor !== '' ? $valor : trim((string) $this->ask($pergunta));
    }
}
