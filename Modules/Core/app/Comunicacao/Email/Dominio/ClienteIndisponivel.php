<?php

namespace Modules\Core\Comunicacao\Email\Dominio;

use Modules\Core\Comunicacao\Email\EstadoConfiguracaoEmail;

/** Sem chave do fornecedor fora de dev/testes: nada se regista nem se verifica, para nunca activar um domínio por engano. */
class ClienteIndisponivel implements ClienteDeDominios
{
    public function registar(string $dominio): array
    {
        throw new ClienteDeDominiosIndisponivel('O envio por domínio próprio ainda não está disponível nesta instalação.');
    }

    public function verificar(string $idExterno): EstadoConfiguracaoEmail
    {
        throw new ClienteDeDominiosIndisponivel('O envio por domínio próprio ainda não está disponível nesta instalação.');
    }
}
