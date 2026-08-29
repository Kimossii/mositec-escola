<?php

namespace Modules\Permissao\Services;

use Modules\Permissao\Actions\AlternarEstadoPerfilAction;
use Modules\Permissao\Actions\AtribuirPerfilAction;
use Modules\Permissao\Actions\AtualizarPerfilAction;
use Modules\Permissao\Actions\CriarPerfilAction;
use Modules\Permissao\Actions\EliminarPerfilAction;
use Modules\Permissao\Actions\RemoverPerfilAction;
use Modules\Permissao\Actions\SincronizarPermissoesPerfilAction;
use Modules\Permissao\Actions\SincronizarPermissoesUtilizadorAction;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;

class GestaoPerfilService
{
    public function __construct(
        private CriarPerfilAction $criarPerfilAction,
        private AtualizarPerfilAction $atualizarPerfilAction,
        private EliminarPerfilAction $eliminarPerfilAction,
        private AtribuirPerfilAction $atribuirPerfilAction,
        private RemoverPerfilAction $removerPerfilAction,
        private SincronizarPermissoesPerfilAction $sincronizarPermissoesPerfilAction,
        private SincronizarPermissoesUtilizadorAction $sincronizarPermissoesUtilizadorAction,
        private AlternarEstadoPerfilAction $alternarEstadoPerfilAction,
    ) {}

    public function criarPerfil(array $dados): Role
    {
        return $this->criarPerfilAction->executar($dados);
    }

    public function atualizarPerfil(Role $role, array $dados): Role
    {
        return $this->atualizarPerfilAction->executar($role, $dados);
    }

    public function eliminarPerfil(Role $role): void
    {
        $this->eliminarPerfilAction->executar($role);
    }

    public function atribuirPerfil(User $user, int $roleId): void
    {
        $this->atribuirPerfilAction->executar($user, $roleId);
    }

    public function removerPerfil(User $user, Role $role): void
    {
        $this->removerPerfilAction->executar($user, $role);
    }

    public function sincronizarPermissoesDoPerfil(Role $role, array $celulas): void
    {
        $this->sincronizarPermissoesPerfilAction->executar($role, $celulas);
    }

    public function sincronizarPermissoesDoUtilizador(User $user, array $celulas): void
    {
        $this->sincronizarPermissoesUtilizadorAction->executar($user, $celulas);
    }

    public function alternarEstadoPerfil(Role $role): Role
    {
        return $this->alternarEstadoPerfilAction->executar($role);
    }
}
