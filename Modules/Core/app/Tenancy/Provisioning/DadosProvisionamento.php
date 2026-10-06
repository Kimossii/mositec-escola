<?php

namespace Modules\Core\Tenancy\Provisioning;

/**
 * Dados que quem cria o tenant fornece aos provisionadores.
 * Sem palavra-passe: a do administrador inicial é gerada pelo provisionador dele.
 */
final readonly class DadosProvisionamento
{
    public function __construct(
        public string $nomeEstabelecimento,
        public string $nomeAdministrador,
        public string $emailAdministrador,
    ) {}
}
