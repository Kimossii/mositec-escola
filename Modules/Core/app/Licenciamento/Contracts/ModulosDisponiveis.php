<?php

namespace Modules\Core\Licenciamento\Contracts;

/** Pergunta única «este módulo está disponível para a escola actual?», para quem não pode importar o Licenciamento. */
interface ModulosDisponiveis
{
    public function disponivel(string $alias): bool;
}
