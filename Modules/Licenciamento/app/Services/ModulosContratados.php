<?php

namespace Modules\Licenciamento\Services;

use Modules\Core\Licenciamento\Contracts\ModulosDisponiveis;

/** Implementa o contrato do Core sobre os direitos da escola actual (flag inactiva ou módulo base: sempre disponível). */
class ModulosContratados implements ModulosDisponiveis
{
    public function __construct(private readonly DireitosService $direitos) {}

    public function disponivel(string $alias): bool
    {
        return ! config('licenciamento.activo') || $this->direitos->moduloDisponivel($alias);
    }
}
