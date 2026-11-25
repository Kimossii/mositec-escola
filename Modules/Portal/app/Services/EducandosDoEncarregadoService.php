<?php

namespace Modules\Portal\Services;

use Illuminate\Support\Collection;
use Modules\Core\Enums\Estado;
use Modules\Usuario\Models\User;

/**
 * Ponto único de acesso aos educandos de um encarregado. Só devolve quem está ligado a ele (nesta escola) e
 * com a conta activa, como o próprio aluno: uma conta inactiva não entra. É aqui que entram, no futuro, outras
 * regras de acesso (ex.: atraso de propina); nunca em cada página.
 */
class EducandosDoEncarregadoService
{
    /** @return Collection<int, User> */
    public function listar(User $encarregado): Collection
    {
        return $encarregado->educandos()
            ->where('users.estado', Estado::ATIVO->value)
            ->orderBy('users.name')
            ->get();
    }

    public function obter(User $encarregado, int $educandoId): ?User
    {
        return $encarregado->educandos()
            ->where('users.estado', Estado::ATIVO->value)
            ->where('users.id', $educandoId)
            ->first();
    }
}
