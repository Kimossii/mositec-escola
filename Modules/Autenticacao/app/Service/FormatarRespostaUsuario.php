<?php

namespace Modules\Autenticacao\Service;

use Illuminate\Contracts\Auth\Authenticatable;

class FormatarRespostaUsuario
{
    public static function formatado(Authenticatable $user): array
    {
        /** @var \Modules\Usuario\Models\User $user */

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'estado' => $user->estado ?? null,
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
        ];
    }
}
