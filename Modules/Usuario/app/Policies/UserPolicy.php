<?php

namespace Modules\Usuario\Policies;

use Illuminate\Auth\Access\Response;
use Modules\Permissao\Enums\Perfil;
use Modules\Usuario\Models\User;

class UserPolicy
{
    public function delete(User $authUser, User $user): Response
    {
        if ($authUser->id === $user->id) {
            return Response::deny('Não pode eliminar a sua própria conta.');
        }

        return $this->autorizadoParaAlvo($authUser, $user, 'eliminar');
    }

    public function alternarEstado(User $authUser, User $user): Response
    {
        return $this->autorizadoParaAlvo($authUser, $user, 'editar');
    }

    // Eliminar/desactivar um Admin Escola é um acto de autorização, não de
    // simples gestão de contas — mesma fronteira usada nas FormRequests.
    private function autorizadoParaAlvo(User $authUser, User $user, string $acao): Response
    {
        $alvoEAdmin = $user->roles->contains('nome', Perfil::ADMIN_ESCOLA->value);
        $ability = ($alvoEAdmin ? 'autorizacao' : 'usuario').".{$acao}";

        return $authUser->can($ability) ? Response::allow() : Response::deny();
    }
}
