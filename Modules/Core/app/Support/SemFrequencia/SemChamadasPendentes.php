<?php

namespace Modules\Core\Support\SemFrequencia;

use Modules\Core\Contracts\ConsultaChamadasPendentes;

/** Sem o módulo Frequencia não há chamadas por fazer. O módulo sobrepõe este binding. */
class SemChamadasPendentes implements ConsultaChamadasPendentes
{
    public function doUtilizador(int $utilizadorId): int
    {
        return 0;
    }

    public function daEscola(): int
    {
        return 0;
    }
}
