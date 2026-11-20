<?php

namespace Modules\Core\Licenciamento\Contracts;

use Modules\Core\Licenciamento\Enums\Recurso;

/** Implementação nula de GarantiaDeLimites: usada quando o módulo Licenciamento está desactivado ou ausente. */
class SemLimites implements GarantiaDeLimites
{
    public function garantir(Recurso $recurso, int $adicionar = 1): void {}
}
