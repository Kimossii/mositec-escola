<?php

namespace Modules\Usuario\DTO;

use Modules\Core\Enums\Estado;

/** Os dados para criar o acesso (conta de utilizador) de um docente já registado. */
readonly class AcessoDeDocenteDTO
{
    public function __construct(
        public int $docenteId,
        public string $email,
        public string $password,
        public Estado $estado = Estado::ATIVO,
    ) {
    }
}
