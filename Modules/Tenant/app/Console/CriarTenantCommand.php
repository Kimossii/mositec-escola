<?php

namespace Modules\Tenant\Console;

use Illuminate\Console\Command;
use Modules\Tenant\Actions\CriarTenantAction;
use Modules\Tenant\DTO\CriarTenantDTO;
use Modules\Tenant\Exceptions\DadosDeTenantInvalidos;
use Modules\Tenant\Exceptions\ProvisionamentoIncompleto;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Recolhe os dados (opções ou perguntas) e chama CriarTenantAction. Sem lógica.
 * Não aceita palavra-passe: a do administrador é temporária e mostrada uma única vez.
 */
class CriarTenantCommand extends Command
{
    protected $signature = 'mosi:tenant:create
        {--nome= : Nome do estabelecimento}
        {--admin-nome= : Nome do administrador inicial}
        {--admin-email= : Email do administrador inicial}
        {--dominio= : Domínio principal}
        {--codigo= : Código já atribuído (MOSI-000000); omitido, é gerado}';

    protected $description = 'Cria uma escola (tenant) de ponta a ponta, com o administrador inicial';

    public function handle(CriarTenantAction $action): int
    {
        $dto = new CriarTenantDTO(
            nomeEstabelecimento: $this->valor('nome', 'Nome do estabelecimento'),
            nomeAdministrador: $this->valor('admin-nome', 'Nome do administrador'),
            emailAdministrador: $this->valor('admin-email', 'Email do administrador'),
            dominioPrincipal: $this->valor('dominio', 'Domínio principal'),
            codigo: ($codigo = trim((string) $this->option('codigo'))) !== '' ? $codigo : null,
        );

        try {
            $criado = $action->executar($dto);
        } catch (DadosDeTenantInvalidos $e) {
            foreach ($e->erros as $erro) {
                $this->error($erro);
            }

            return self::FAILURE;
        } catch (ProvisionamentoIncompleto $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            // Sem getMessage(): numa QueryException traria SQL e bindings.
            report($e);
            $this->error('Falha inesperada (' . $e::class . '); nada foi criado. Consulte os logs.');

            return self::FAILURE;
        }

        $this->info('Tenant criado.');
        $this->line("Código:         {$criado->tenant->codigo}");
        $this->line("Domínio:        {$criado->dominio->dominio}");

        $this->line("Administrador:  {$criado->credencial->email}");
        // RAW: a senha pode conter \ < > / que o formatador do Symfony trataria como marcação.
        $this->output->writeln('Senha temporária: ' . $criado->credencial->senha(), OutputInterface::OUTPUT_RAW);
        $this->warn('A senha é mostrada uma única vez; será exigida a troca no primeiro acesso.');

        return self::SUCCESS;
    }

    private function valor(string $opcao, string $pergunta): string
    {
        $valor = trim((string) $this->option($opcao));

        return $valor !== '' ? $valor : trim((string) $this->ask($pergunta));
    }
}
