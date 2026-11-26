<?php

namespace Modules\Core\Comunicacao\Email;

use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * O canal `mail` do Laravel, com o registo de cada tentativa (enviada ou falhada). Quando o envio falha a excepção continua a
 * subir: a fila repete e dá o trabalho como falhado exactamente como antes, só que agora fica o rasto em `envios_email`.
 */
class CanalEmailDeTenant
{
    public function __construct(private MailChannel $mail, private RegistoDeEnvios $registo) {}

    public function send(object $destinatario, Notification $notificacao): mixed
    {
        try {
            $resposta = $this->mail->send($destinatario, $notificacao);
        } catch (Throwable $erro) {
            if ($notificacao instanceof NotificacaoDeTenant) {
                $this->registo->falhou($notificacao, $destinatario, $erro);
            }

            throw $erro;
        }

        if ($notificacao instanceof NotificacaoDeTenant) {
            $this->registo->enviado($notificacao, $destinatario);
        }

        return $resposta;
    }
}
