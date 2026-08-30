<?php

namespace Modules\Usuario\Policies;

use Illuminate\Auth\Access\Response;
use Modules\Usuario\Models\User;

class UserPolicy
{
    public function delete(User $authUser, User $user): Response
    {
        return $authUser->id !== $user->id
            ? Response::allow()
            : Response::deny('Não pode eliminar a sua própria conta.');
    }
}
