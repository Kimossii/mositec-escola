<?php

namespace Modules\Licenciamento\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Mostra um par de chaves Ed25519 novo (base64). Não grava nada em disco nem na BD: quem corre copia a pública para a
 * config e guarda a privada, só, no ficheiro do emissor. Sem lógica de negócio.
 */
class GerarChavesCommand extends Command
{
    protected $signature = 'licenciamento:gerar-chaves {--kid=k1 : Identificador da chave (kid)}';

    protected $description = 'Gera um par de chaves Ed25519 para assinar licenças (só mostra; não grava nada)';

    public function handle(): int
    {
        $par = sodium_crypto_sign_keypair();
        $kid = trim((string) $this->option('kid')) ?: 'k1';
        $publica = base64_encode(sodium_crypto_sign_publickey($par));
        $privada = base64_encode(sodium_crypto_sign_secretkey($par));
        sodium_memzero($par);

        $this->warn('A chave PRIVADA assina licenças: quem a tiver emite licenças válidas. Não a faça commit, não a cole em chats, tickets nem no .env.');
        $this->newLine();
        $this->line("kid: {$kid}");
        $this->line('Chave PÚBLICA (vai em config/licenciamento.php > chaves_publicas, sob este kid; é distribuída com a aplicação):');
        $this->output->writeln($publica, OutputInterface::OUTPUT_RAW);
        $this->newLine();
        $this->line('Chave PRIVADA (só no ficheiro do emissor, fora da raiz web, permissões 600; o .env só leva o caminho em LICENCIAMENTO_CHAVE_PRIVADA_FICHEIRO e o kid em LICENCIAMENTO_KID):');
        $this->output->writeln($privada, OutputInterface::OUTPUT_RAW);
        $this->newLine();
        $this->warn('Nada foi gravado. Guarde a privada agora e limpe o histórico do terminal.');

        return self::SUCCESS;
    }
}
