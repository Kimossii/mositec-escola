<?php

namespace Modules\Core\Licenciamento\Contracts;

use Modules\Core\Licenciamento\Enums\Recurso;
use Modules\Core\Licenciamento\Exceptions\LimiteDeLicencaExcedido;

/**
 * Garante que criar mais `$adicionar` unidades de um recurso não ultrapassa o limite da licença do tenant actual.
 * Chamado DENTRO da transacção da Action que cria o recurso. A implementação por omissão (Core) não faz nada;
 * o módulo Licenciamento, quando activo, sobrepõe o binding.
 */
interface GarantiaDeLimites
{
    /** @throws LimiteDeLicencaExcedido */
    public function garantir(Recurso $recurso, int $adicionar = 1): void;
}
