<?php

namespace Modules\AnoLectivo\Policies;

use Modules\Usuario\Models\User;

class EventoCalendarioPolicy
{
    public function view(User $user): bool
    {
        return $user->can('gerir-ano-letivo');
    }

    public function create(User $user): bool
    {
        return $user->can('gerir-ano-letivo');
    }

    public function update(User $user): bool
    {
        return $user->can('gerir-ano-letivo');
    }

    public function delete(User $user): bool
    {
        return $user->can('gerir-ano-letivo');
    }
}
