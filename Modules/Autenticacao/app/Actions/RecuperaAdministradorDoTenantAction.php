<?php

namespace Modules\Autenticacao\Actions;

use Modules\Core\Tenancy\Contracts\CatalogoDeTenants;
use Modules\Core\Tenancy\Contracts\RecuperaAdministradorDoTenant;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Core\Tenancy\Enums\MotivoRecusaRecuperacao;
use Modules\Core\Tenancy\Exceptions\RecuperacaoDeAdministradorRecusada;
use Modules\Core\Tenancy\Provisioning\AdministradorDaEscola;
use Modules\Core\Tenancy\Provisioning\CredencialInicial;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Core\Tenancy\TenantContext;
use Modules\Permissao\Enums\Perfil;
use Modules\Usuario\Models\User;

/**
 * Implementa o contrato do Core para o painel da Plataforma. É AQUI que o contexto da escola se abre
 * (`executarComo`, restaurado em `finally` pelo TenantContext, mesmo com excepção): quem chama nunca
 * o abre. A recuperação em si é a RecuperarAdministradorAction, a mesma do comando
 * `mosi:tenant:admin:reset`. Só escolas Activas, com o estado lido de novo (o instantâneo recebido
 * pode estar desactualizado).
 */
class RecuperaAdministradorDoTenantAction implements RecuperaAdministradorDoTenant
{
    public function __construct(
        private readonly TenantContext $contexto,
        private readonly CatalogoDeTenants $catalogo,
        private readonly RecuperarAdministradorAction $recuperar,
    ) {}

    public function administradores(TenantAtual $tenant): array
    {
        $escola = $this->escolaActiva($tenant);

        return $this->contexto->executarComo($escola, fn () => User::query()
            ->whereHas('roles', fn ($roles) => $roles->where('nome', Perfil::ADMIN_ESCOLA->value))
            ->where('estado', 1)
            ->orderBy('name')
            ->orderBy('email')
            ->get(['name', 'email'])
            ->map(fn (User $u) => new AdministradorDaEscola(nome: $u->name, email: $u->email))
            ->all());
    }

    public function recuperar(TenantAtual $tenant, ?string $email = null): CredencialInicial
    {
        $escola = $this->escolaActiva($tenant);

        return $this->contexto->executarComo($escola, fn () => $this->recuperar->executar($email));
    }

    /**
     * @throws RecuperacaoDeAdministradorRecusada
     */
    private function escolaActiva(TenantAtual $tenant): TenantAtual
    {
        $escola = $this->catalogo->porId($tenant->id);

        if ($escola === null || $escola->estado !== EstadoTenant::ACTIVO) {
            throw new RecuperacaoDeAdministradorRecusada(
                'Só é possível recuperar o administrador de uma escola activa.',
                MotivoRecusaRecuperacao::ESCOLA_NAO_ACTIVA,
            );
        }

        return $escola;
    }
}
