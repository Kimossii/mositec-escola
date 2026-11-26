<?php

namespace Modules\Core\Comunicacao\Email;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Core\Licenciamento\Contracts\ModulosDisponiveis;
use Modules\Core\Tenancy\Jobs\ComTenant;

/**
 * Base de toda a notificação por email de uma escola. Garante, sem cada notificação ter de se lembrar:
 * - vai sempre para a fila com o tenant no payload (ComTenant), por isso o remetente e os links são os da
 *   escola certa mesmo no worker;
 * - o From sai de RemetenteTenant (use mensagemBase());
 * - regista cada tentativa em `envios_email` (enviada, falhada ou descartada), com o destinatário mascarado;
 * - respeita o limite por hora do tenant, por categoria;
 * - os OPCIONAIS só saem se a escola tiver o módulo licenciável «notificacoes» contratado; os ESSENCIAIS nunca
 *   dependem dele.
 *
 * A subclasse só declara categoria() e monta o conteúdo em toMail() a partir de mensagemBase(). Os links
 * vêm de RemetenteTenant::url(), nunca de route()/url(), que num worker não conhecem o domínio da escola.
 * Nunca meter senhas nem tokens de sessão no corpo; ligações com token expiram.
 */
abstract class NotificacaoDeTenant extends Notification implements ShouldQueue
{
    use ComTenant, Queueable;

    /** Alias do módulo licenciável (config/licenciamento.php) que dá direito aos emails opcionais. */
    public const MODULO_NOTIFICACOES = 'notificacoes';

    abstract public function categoria(): CategoriaEmail;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        // O canal `mail` com registo de cada tentativa (CanalEmailDeTenant).
        return [CanalEmailDeTenant::class];
    }

    /** Corre no worker (dentro do contexto do tenant), imediatamente antes de enviar. */
    public function shouldSend(object $notifiable, string $canal): bool
    {
        if ($this->categoria() === CategoriaEmail::OPCIONAL && ! app(ModulosDisponiveis::class)->disponivel(self::MODULO_NOTIFICACOES)) {
            app(RegistoDeEnvios::class)->descartado($this, $notifiable, EstadoEnvioEmail::DESCARTADO_CONTRATO);

            return false;
        }

        if (! app(LimitadorDeEmailsPorTenant::class)->permitir($this->categoria())) {
            app(RegistoDeEnvios::class)->descartado($this, $notifiable, EstadoEnvioEmail::DESCARTADO_LIMITE);

            return false;
        }

        return true;
    }

    protected function mensagemBase(): MailMessage
    {
        $remetente = app(RemetenteTenant::class)->resolver();

        $mensagem = (new MailMessage)
            ->from($remetente->endereco, $remetente->nome)
            ->salutation(app(RemetenteTenant::class)->assinatura());

        if ($remetente->replyTo !== null) {
            $mensagem->replyTo($remetente->replyTo);
        }

        return $mensagem;
    }
}
