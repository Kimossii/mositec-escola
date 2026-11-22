<?php

namespace Modules\Core\Licenciamento\Contracts;

/** Implementação nula de ModulosDisponiveis: sem o módulo Licenciamento, todos os módulos estão disponíveis. */
class TodosOsModulos implements ModulosDisponiveis
{
    public function disponivel(string $alias): bool
    {
        return true;
    }
}
