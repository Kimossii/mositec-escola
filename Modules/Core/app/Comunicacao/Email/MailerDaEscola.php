<?php

namespace Modules\Core\Comunicacao\Email;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Core\Models\ConfiguracaoEmail;
use Modules\Core\Tenancy\TenantContext;

/**
 * Infra de email própria da escola corrente: só existe quando a configuração está verificada E activa. Qualquer outro
 * estado (por verificar, com falha, desligada) cai na central, nunca numa infra por provar. Regista, em tempo de execução,
 * o mailer `escola` com os dados da escola; como a config muda por escola, o mailer é sempre reconstruído (purge), nunca reutilizado.
 */
class MailerDaEscola
{
    public const MAILER = 'escola';

    public function __construct(private TenantContext $contexto, private ValidadorDeServidorSmtp $validador) {}

    /** A configuração em uso da escola corrente, ou null (sem tenant, sem configuração ou não verificada/activa). */
    public function configuracao(): ?ConfiguracaoEmail
    {
        if (! $this->contexto->temTenant()) {
            return null;
        }

        $configuracao = ConfiguracaoEmail::query()->first();

        if (! $configuracao?->estaEmUso()) {
            return null;
        }

        // Revalida o servidor (SSRF) em cada envio, não só ao guardar/testar: um DNS que passou a apontar à rede interna
        // depois da verificação faz a escola cair na central. Não elimina por completo a janela entre esta resolução e a
        // ligação (o transporte SMTP do Laravel não permite fixar o IP validado mantendo o nome para o TLS).
        if ($this->validador->erro($configuracao->servidor, (int) $configuracao->porta) !== null) {
            Log::warning('Servidor SMTP da escola recusado na revalidação; a usar a central.', ['tenant_id' => $configuracao->tenant_id]);

            return null;
        }

        return $configuracao;
    }

    /** Nome do mailer a usar (já registado), ou null para a central. Lê a configuração; quem já a tem usa nomeDe(). */
    public function nome(): ?string
    {
        return $this->nomeDe($this->configuracao());
    }

    /** Regista o mailer da configuração dada (a mesma que decidiu o remetente) e devolve o seu nome; null → central. */
    public function nomeDe(?ConfiguracaoEmail $configuracao): ?string
    {
        if ($configuracao === null) {
            return null;
        }

        config(['mail.mailers.'.self::MAILER => self::configDe($configuracao)]);
        Mail::purge(self::MAILER);

        return self::MAILER;
    }

    public function canal(): CanalEnvioEmail
    {
        return $this->configuracao() !== null ? CanalEnvioEmail::PROPRIO : CanalEnvioEmail::CENTRAL;
    }

    /** Configuração de mailer (smtp) de Laravel/Symfony. Usada também pelo teste de envio, antes de a configuração estar activa. */
    public static function configDe(ConfiguracaoEmail $c): array
    {
        $encriptacao = $c->encriptacao ?? EncriptacaoSmtp::NENHUMA;

        $config = [
            'transport' => 'smtp',
            'host' => $c->servidor,
            'port' => (int) $c->porta,
            'scheme' => $encriptacao === EncriptacaoSmtp::SSL ? 'smtps' : 'smtp',
            'username' => $c->utilizador !== null && $c->utilizador !== '' ? $c->utilizador : null,
            'password' => $c->utilizador !== null && $c->utilizador !== '' ? $c->senha : null,
            'timeout' => (int) ($c->tempo_limite ?: 15),
            'verify_peer' => (bool) $c->validar_certificado,
        ];

        if ($c->dominio_ehlo !== null && $c->dominio_ehlo !== '') {
            $config['local_domain'] = $c->dominio_ehlo;
        }

        if ($encriptacao === EncriptacaoSmtp::STARTTLS) {
            $config['require_tls'] = true;
        }

        if ($encriptacao === EncriptacaoSmtp::NENHUMA) {
            $config['auto_tls'] = false;
        }

        return $config;
    }
}
