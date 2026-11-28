<?php

namespace Modules\Core\Comunicacao\Email;

/**
 * «Há um canal de email realmente configurado nesta instalação?» Tudo o que depende de email (recuperação de senha,
 * confirmação de email, notificações) deve perguntar aqui antes de se oferecer ao utilizador, para nunca prometer um
 * email que não vai chegar.
 *
 * Só diz que o canal está CONFIGURADO, não que a entrega está garantida: um servidor mal apontado só se descobre ao enviar.
 * `log` e `array` (desenvolvimento e testes) escrevem num ficheiro ou na memória e por isso não contam como disponíveis.
 * Num servidor próprio o mailer é o do `.env` dessa instalação, nunca a conta da MosiTec.
 *
 * Uma escola com infra própria verificada e activa (configuracoes_email) conta como disponível, seja qual for o mailer central.
 */
class EmailDisponivel
{
    public function disponivel(): bool
    {
        if (app(MailerDaEscola::class)->configuracao() !== null || app(MailerDaEscola::class)->dominio() !== null) {
            return true;
        }

        $mailer = (string) config('mail.default');

        return match ($mailer) {
            '', 'log', 'array' => false,
            'resend' => $this->preenchido(config('services.resend.key')),
            'smtp' => $this->preenchido(config('mail.mailers.smtp.host')),
            default => config("mail.mailers.{$mailer}") !== null,
        };
    }

    private function preenchido(mixed $valor): bool
    {
        return is_string($valor) && trim($valor) !== '';
    }
}
