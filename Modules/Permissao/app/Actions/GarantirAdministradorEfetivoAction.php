<?php

namespace Modules\Permissao\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Permissao\Services\PermissionResolver;
use Modules\Usuario\Models\User;

/**
 * Invariante de segurança: nunca deixar o sistema sem NENHUM utilizador com
 * autorizacao.editar — essa é a única permissão que permite corrigir
 * perfis/permissões depois, por isso perdê-la de vez é um lockout sem volta
 * (ninguém mais consegue devolvê-la a ninguém através da aplicação).
 *
 * Chamado no fim de qualquer acção que possa REDUZIR essa concessão
 * (desactivar/editar/eliminar perfil, sincronizar permissões de perfil,
 * remover perfil de um utilizador, sincronizar overrides de um utilizador,
 * eliminar um utilizador) — nunca nas que só podem aumentá-la.
 */
class GarantirAdministradorEfetivoAction
{
    public function __construct(private readonly PermissionResolver $resolver)
    {
    }

    public function verificar(): void
    {
        $existe = User::all()->contains(
            fn (User $user) => $this->resolver->can($user, 'autorizacao.editar'),
        );

        if (!$existe) {
            throw ValidationException::withMessages([
                'autorizacao' => 'Esta ação deixaria o sistema sem nenhum utilizador com a permissão autorizacao.editar — cancelada para evitar perder o acesso à gestão de perfis e permissões.',
            ]);
        }
    }
}
