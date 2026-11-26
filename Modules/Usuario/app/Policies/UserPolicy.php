<?php

namespace Modules\Usuario\Policies;

use Illuminate\Auth\Access\Response;
use Modules\Core\Enums\Estado;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Services\PermissionResolver;
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

    public function redefinirSenha(User $authUser, User $user): Response
    {
        return $this->gerirCredenciais($authUser, $user, 'a sua própria senha', 'a senha');
    }

    /** Repor o 2FA de outra conta: as mesmas regras de redefinir a senha (permissão, nunca a própria, admins só por admins). */
    public function redefinirDoisFactores(User $authUser, User $user): Response
    {
        return $this->gerirCredenciais($authUser, $user, 'o seu próprio 2FA', 'o 2FA');
    }

    /**
     * Enviar a confirmação de email de OUTRA conta: mesma fronteira de «redefinir senha» (permissão `senha-utilizador.editar`,
     * contas com privilégios de administração só por administradores). Para a própria conta há o botão em Definições.
     */
    public function enviarConfirmacaoDeEmail(User $authUser, User $user): Response
    {
        if ($authUser->is($user)) {
            return Response::deny('Para a sua própria conta use as Definições.');
        }

        return $this->gerirCredenciais($authUser, $user, 'o seu próprio email', 'o email');
    }

    private function gerirCredenciais(User $authUser, User $user, string $proprio, string $objecto): Response
    {
        if ($authUser->is($user)) {
            return Response::deny("Não pode redefinir {$proprio}.");
        }

        if (! $authUser->can('senha-utilizador.editar')) {
            return Response::deny();
        }

        $alvoPrivilegiado = $this->eAlvoPrivilegiado($user);
        // Só roles activos contam para o autor (como o PermissionResolver); no alvo conta qualquer role admin.
        $autorEAdmin = $authUser->roles()
            ->where('estado', Estado::ATIVO->value)
            ->where('nome', Perfil::ADMIN_ESCOLA->value)
            ->exists();

        if ($alvoPrivilegiado && ! $autorEAdmin) {
            return Response::deny("Só um Administrador da Escola pode redefinir {$objecto} de uma conta com privilégios de administração.");
        }

        return Response::allow();
    }

    /**
     * Privilegiado: role ADMIN_ESCOLA (qualquer estado) ou permissões efectivas que dão
     * gestão de autorização ou de senhas, venham de perfis activos ou de user_permissoes.
     * Fecha a escalada por perfil personalizado ou permissão directa.
     */
    private function eAlvoPrivilegiado(User $alvo): bool
    {
        if ($alvo->roles->contains('nome', Perfil::ADMIN_ESCOLA->value)) {
            return true;
        }

        foreach (app(PermissionResolver::class)->conjuntoConcedido($alvo) as $permissao) {
            if (str_starts_with($permissao, 'autorizacao.') || $permissao === 'senha-utilizador.editar') {
                return true;
            }
        }

        return false;
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
