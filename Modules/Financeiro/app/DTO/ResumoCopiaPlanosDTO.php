<?php

namespace Modules\Financeiro\DTO;

class ResumoCopiaPlanosDTO
{
    /**
     * @param  list<array{id: int, nome: string}>  $copiados
     * @param  list<array{nome: string, motivo: string}>  $ignorados
     */
    public function __construct(
        public array $copiados = [],
        public array $ignorados = [],
    ) {
    }

    public function copiou(string $nome, int $id): void
    {
        $this->copiados[] = ['id' => $id, 'nome' => $nome];
    }

    public function ignorou(string $nome, string $motivo): void
    {
        $this->ignorados[] = ['nome' => $nome, 'motivo' => $motivo];
    }

    /**
     * @return array{copiados: list<array{id: int, nome: string}>, ignorados: list<array{nome: string, motivo: string}>}
     */
    public function toArray(): array
    {
        return ['copiados' => $this->copiados, 'ignorados' => $this->ignorados];
    }
}
