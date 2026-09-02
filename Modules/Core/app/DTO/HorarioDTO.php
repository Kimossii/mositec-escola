<?php

namespace Modules\Core\DTO;

use Modules\Core\Enums\EstadoHorario;

class HorarioDTO
{
    public function __construct(
        public readonly string $nome,
        public readonly string $hora_inicio,
        public readonly string $hora_fim,
        public readonly EstadoHorario $estado = EstadoHorario::ATIVO,
        public readonly ?int $criado_por = null,
        public readonly ?int $editado_por = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'nome' => $this->nome,
            'hora_inicio' => $this->hora_inicio,
            'hora_fim' => $this->hora_fim,
            'estado' => $this->estado->value,
            'criado_por' => $this->criado_por,
            'editado_por' => $this->editado_por,
        ];
    }
}
