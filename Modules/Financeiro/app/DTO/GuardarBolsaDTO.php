<?php

namespace Modules\Financeiro\DTO;

/** Os dados de uma bolsa. `matricula_id` só vem na criação: a bolsa nasce da matrícula escolhida (aluno + ano lectivo). */
final class GuardarBolsaDTO
{
    public function __construct(
        public readonly string $nome,
        public readonly string $percentagem,
        public readonly string $dataInicio,
        public readonly string $dataFim,
        public readonly string $motivo,
        public readonly ?int $matriculaId = null,
    ) {
    }
}
