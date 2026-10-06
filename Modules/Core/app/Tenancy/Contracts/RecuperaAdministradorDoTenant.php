<?php

namespace Modules\Core\Tenancy\Contracts;

use Modules\Core\Tenancy\Exceptions\RecuperacaoDeAdministradorRecusada;
use Modules\Core\Tenancy\Provisioning\AdministradorDaEscola;
use Modules\Core\Tenancy\Provisioning\CredencialInicial;
use Modules\Core\Tenancy\TenantAtual;

/**
 * Recuperar o acesso do administrador de uma escola a partir de fora dela (painel da Plataforma).
 * Implementado pelo módulo Autenticacao, que abre o contexto da escola POR DENTRO (executarComo, com
 * restauro em `finally`): quem chama nunca abre contexto de tenant. Só aceita escolas Activas.
 * Sem o módulo que o implementa, resolver o contrato falha (nunca devolve "lista vazia").
 */
interface RecuperaAdministradorDoTenant
{
    /**
     * Administradores ACTIVOS (perfil Administrador da Escola) da escola, por nome.
     *
     * @return list<AdministradorDaEscola>
     *
     * @throws RecuperacaoDeAdministradorRecusada escola não activa
     */
    public function administradores(TenantAtual $tenant): array;

    /**
     * Nova senha temporária (só o hash fica gravado, troca obrigatória, sessões e tokens do
     * administrador invalidados). `$email` é obrigatório se houver mais de um administrador activo.
     *
     * @throws RecuperacaoDeAdministradorRecusada
     */
    public function recuperar(TenantAtual $tenant, ?string $email = null): CredencialInicial;
}
