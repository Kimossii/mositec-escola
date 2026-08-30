<?php

namespace Modules\Usuario\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Usuario\Models\User;

class EliminarUsuarioAction
{
    public function executar(User $user): void
    {
        if ($user->educandos()->exists()) {
            throw ValidationException::withMessages([
                'utilizador' => 'Este utilizador tem educandos vinculados e não pode ser eliminado.',
            ]);
        }

        if ($user->encarregados()->exists()) {
            throw ValidationException::withMessages([
                'utilizador' => 'Este utilizador tem encarregados vinculados e não pode ser eliminado.',
            ]);
        }

        $user->delete();
    }
}
