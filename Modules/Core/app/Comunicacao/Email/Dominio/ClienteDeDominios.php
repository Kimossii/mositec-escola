<?php

namespace Modules\Core\Comunicacao\Email\Dominio;

use Modules\Core\Comunicacao\Email\EstadoConfiguracaoEmail;

/** Fornecedor de envio por domínio verificado (Resend). Atrás de interface para testar sem chave real. */
interface ClienteDeDominios
{
    /**
     * Regista o domínio no fornecedor.
     *
     * @return array{id: string, registos: list<array{tipo: string, nome: string, valor: string}>}
     *
     * @throws ClienteDeDominiosIndisponivel
     */
    public function registar(string $dominio): array;

    /** @throws ClienteDeDominiosIndisponivel */
    public function verificar(string $idExterno): EstadoConfiguracaoEmail;
}
