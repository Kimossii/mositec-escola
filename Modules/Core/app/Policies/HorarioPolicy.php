<?php

namespace Modules\Core\Policies;

use App\Models\User;
use Modules\Core\Models\Horario;

class HorarioPolicy
{
    /**
     * Determina se o utilizador pode visualizar horários.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('visualizar', Horario::class);
    }

    /**
     * Determina se o utilizador pode visualizar um horário.
     */
    public function view(User $user, Horario $horario): bool
    {
        return $user->can('visualizar', $horario);
    }

    /**
     * Determina se o utilizador pode criar horários.
     */
    public function create(User $user): bool
    {
        return $user->can('criar', Horario::class);
    }

    /**
     * Determina se o utilizador pode atualizar um horário.
     */
    public function update(User $user, Horario $horario): bool
    {
        return $user->can('editar', $horario);
    }

    /**
     * Determina se o utilizador pode eliminar um horário.
     */
    public function delete(User $user, Horario $horario): bool
    {
        return $user->can('eliminar', $horario);
    }
}

