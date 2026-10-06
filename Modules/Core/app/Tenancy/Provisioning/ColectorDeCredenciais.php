<?php

namespace Modules\Core\Tenancy\Provisioning;

/**
 * Caminho pelo qual a senha temporária sai do provisionador sem passar pelo contrato
 * (que devolve void). O provisionador regista; a Action que orquestra retira-a, uma só vez.
 * Registado com âmbito de pedido: nunca sobrevive ao pedido/comando.
 */
class ColectorDeCredenciais
{
    private ?CredencialInicial $credencial = null;

    public function registar(CredencialInicial $credencial): void
    {
        $this->credencial = $credencial;
    }

    /** Entrega a credencial e esquece-a. */
    public function retirar(): ?CredencialInicial
    {
        $credencial = $this->credencial;
        $this->credencial = null;

        return $credencial;
    }

    public function esquecer(): void
    {
        $this->credencial = null;
    }
}
