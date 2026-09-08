<?php

namespace Modules\Usuario\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Permissao\Actions\GarantirAdministradorEfetivoAction;
use Modules\Permissao\Support\PermissaoCache;
use Modules\Usuario\Models\User;

class EliminarUsuarioAction
{
    public function __construct(
        private readonly PermissaoCache $cache,
        private readonly GarantirAdministradorEfetivoAction $garantirAdministrador,
    ) {
    }

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

        DB::transaction(function () use ($user) {
            $userId = $user->id;
            $user->delete();
            $this->cache->esquecerUtilizador($userId);
            $this->garantirAdministrador->verificar();
        });
    }
}
