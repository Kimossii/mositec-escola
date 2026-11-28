<?php

namespace Modules\Core\Comunicacao\Email\Dominio;

use Modules\Core\Comunicacao\Email\EstadoConfiguracaoEmail;

/** Só para desenvolvimento e testes. O domínio decide o resultado: contém «falha» → com falha; «pendente» → por verificar; senão verificado. */
class ClienteSimulado implements ClienteDeDominios
{
    public function registar(string $dominio): array
    {
        return [
            'id' => 'simulado:'.$dominio,
            'registos' => [
                ['tipo' => 'TXT', 'nome' => 'send.'.$dominio, 'valor' => 'v=spf1 include:amazonses.com ~all'],
                ['tipo' => 'TXT', 'nome' => 'resend._domainkey.'.$dominio, 'valor' => 'p=SIMULADO'],
            ],
        ];
    }

    public function verificar(string $idExterno): EstadoConfiguracaoEmail
    {
        return match (true) {
            str_contains($idExterno, 'falha') => EstadoConfiguracaoEmail::COM_FALHA,
            str_contains($idExterno, 'pendente') => EstadoConfiguracaoEmail::POR_VERIFICAR,
            default => EstadoConfiguracaoEmail::VERIFICADO,
        };
    }
}
