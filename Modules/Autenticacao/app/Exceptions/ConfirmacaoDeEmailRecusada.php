<?php

namespace Modules\Autenticacao\Exceptions;

use DomainException;

/** Pedido de confirmação de email recusado por uma regra de negócio (traz a mensagem para o utilizador). */
class ConfirmacaoDeEmailRecusada extends DomainException
{
    public static function emailIndisponivel(): self
    {
        return new self('O envio de emails não está configurado nesta escola. Fale com o administrador.');
    }

    public static function semEmail(): self
    {
        return new self('Esta conta não tem um email de acesso para confirmar.');
    }

    public static function jaConfirmado(): self
    {
        return new self('O seu email já está confirmado.');
    }

    public static function falhaNoEnvio(): self
    {
        return new self('Não foi possível enviar o email agora. Tente de novo mais tarde ou fale com o administrador.');
    }

    public static function demasiadosPedidos(): self
    {
        return new self('Já pediu várias confirmações. Aguarde um pouco antes de tentar de novo.');
    }
}
