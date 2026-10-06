<?php

namespace Modules\Core\Tenancy\Contracts;

use Modules\Core\Tenancy\Provisioning\DadosProvisionamento;
use Modules\Core\Tenancy\TenantAtual;

/**
 * Passo do provisioning de um tenant novo. Cada módulo regista o seu no container
 * com a etiqueta ETIQUETA; quem cria o tenant descobre-os só por ela (spec §16).
 *
 * É chamado dentro de TenantContext::executarComo($tenant) e de uma transacção única:
 * qualquer excepção desfaz a criação inteira.
 */
interface ProvisionaTenant
{
    /** Etiqueta comum do container. */
    public const ETIQUETA = 'tenant.provisionadores';

    /** Posição na sequência (crescente). Duas ordens iguais são um erro. */
    public function ordem(): int;

    public function provisionar(TenantAtual $tenant, DadosProvisionamento $dados): void;
}
