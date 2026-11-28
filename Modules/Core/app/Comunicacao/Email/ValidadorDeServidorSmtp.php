<?php

namespace Modules\Core\Comunicacao\Email;

use Closure;

/**
 * Protege o servidor contra SSRF: uma escola não pode apontar o «SMTP» para a rede interna da plataforma.
 * Só portas SMTP habituais e nenhum endereço privado, loopback ou link-local (resolvendo o nome; basta um recusado).
 * Em instalação única (TENANCY_MODO=unico) o servidor é da própria escola, por isso os endereços privados passam
 * (a porta continua validada).
 *
 * A resolução DNS é injectável para os testes.
 */
class ValidadorDeServidorSmtp
{
    public const PORTAS = [25, 465, 587, 2525];

    public function __construct(private ?Closure $resolver = null) {}

    /** Mensagem em português, ou null se o servidor é aceitável. */
    public function erro(string $servidor, int $porta): ?string
    {
        return $this->validar($servidor, $porta)[0];
    }

    /**
     * Valida resolvendo UMA vez e devolve também o IP aprovado a que ligar (fixado no transporte, para o nome não ser resolvido
     * outra vez). O IP é null quando não há nada a fixar: servidor já dado como IP, ou instalação única (sem restrição de rede).
     *
     * @return array{0: ?string, 1: ?string} [erro, ip]
     */
    public function validar(string $servidor, int $porta): array
    {
        if (! in_array($porta, self::PORTAS, true)) {
            return ['A porta tem de ser uma de: '.implode(', ', self::PORTAS).'.', null];
        }

        if (config('tenancy.modo') === 'unico') {
            return [null, null];
        }

        $servidor = trim($servidor, " \t\n\r\0\x0B[]");
        $literal = filter_var($servidor, FILTER_VALIDATE_IP) !== false;
        $ips = $literal ? [$servidor] : $this->resolver()($servidor);

        if (! is_array($ips) || $ips === []) {
            return ['Não foi possível resolver o endereço do servidor.', null];
        }

        foreach ($ips as $ip) {
            if (! $this->publico($ip)) {
                return ['O servidor aponta para um endereço privado ou interno, que não é permitido.', null];
            }
        }

        return [null, $literal ? null : $ips[0]];
    }

    /** Só endereços globalmente encaminháveis; recusa ainda NAT64 (64:ff9b::/96) e 6to4 (2002::/16), que embrulham IPv4 internos. */
    private function publico(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE)) {
            return false;
        }

        $normalizado = strtolower($ip);

        return ! str_starts_with($normalizado, '64:ff9b:') && ! str_starts_with($normalizado, '2002:');
    }

    private function resolver(): Closure
    {
        return $this->resolver ?? static function (string $nome): array {
            $ips = @gethostbynamel($nome) ?: [];

            foreach (@dns_get_record($nome, DNS_AAAA) ?: [] as $registo) {
                $ips[] = $registo['ipv6'];
            }

            return $ips;
        };
    }
}
