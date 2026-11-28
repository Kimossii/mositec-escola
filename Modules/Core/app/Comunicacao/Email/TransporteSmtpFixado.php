<?php

namespace Modules\Core\Comunicacao\Email;

use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/**
 * Transporte SMTP que liga ao IP que o ValidadorDeServidorSmtp já aprovou (`ip`), em vez de resolver o nome outra vez. Fecha a janela de
 * DNS rebinding: entre validar e ligar não há segunda resolução que um atacante possa trocar. O certificado TLS continua a ser
 * verificado contra o NOME do servidor (`peer_name`, que serve também de SNI), nunca contra o IP.
 *
 * Tudo o resto é o transporte SMTP normal do Symfony (mesmas opções que o Laravel passa: scheme, verify_peer, require_tls, auto_tls, local_domain).
 * Sem `ip` (servidor já dado como IP, ou instalação única) liga ao `host`.
 */
final class TransporteSmtpFixado
{
    public const NOME = 'smtp-fixado';

    public static function criar(array $config): EsmtpTransport
    {
        $host = (string) $config['host'];

        $transporte = (new EsmtpTransportFactory)->create(new Dsn(
            $config['scheme'] ?? 'smtp',
            $config['ip'] ?? $host,
            $config['username'] ?? null,
            $config['password'] ?? null,
            $config['port'] ?? null,
            $config,
        ));

        $stream = $transporte->getStream();

        if ($stream instanceof SocketStream) {
            $opcoes = $stream->getStreamOptions();
            $opcoes['ssl']['peer_name'] = $host;
            $stream->setStreamOptions($opcoes);

            if (isset($config['timeout'])) {
                $stream->setTimeout((float) $config['timeout']);
            }
        }

        return $transporte;
    }
}
