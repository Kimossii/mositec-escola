<?php

namespace Modules\Core\Tenancy\Provisioning;

/**
 * O que a Plataforma pode saber de um administrador de uma escola: nome e e-mail (a lista só tem administradores activos). Nada de
 * perfis, permissões, ids ou dados académicos; é o mínimo para escolher qual recuperar.
 */
final readonly class AdministradorDaEscola
{
    public function __construct(
        public string $nome,
        public string $email,
    ) {}
}
