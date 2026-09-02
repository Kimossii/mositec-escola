<?php

namespace Modules\Core\Policies;

use Modules\Usuario\Models\User;

class HorarioPolicy
{
    /**
     * Horario é um bloco reutilizável do Core — a autorização é delegada à
     * gate já existente para a configuração do estabelecimento, em vez de
     * o Core definir a sua própria gate.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('gerir-estabelecimento');
    }

    public function view(User $user): bool
    {
        return $user->can('gerir-estabelecimento');
    }

    public function create(User $user): bool
    {
        return $user->can('gerir-estabelecimento');
    }

    public function update(User $user): bool
    {
        return $user->can('gerir-estabelecimento');
    }

    public function delete(User $user): bool
    {
        return $user->can('gerir-estabelecimento');
    }
}
