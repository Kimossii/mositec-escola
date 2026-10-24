<?php

namespace Modules\Core\DTO;

/**
 * Resultado de uma verificação prévia ao trancamento de uma matrícula. `bloqueios` impedem o trancamento;
 * `avisos` são informativos (ex.: crédito disponível, dívida com a exigência desactivada).
 * Cada item: ['mensagem' => string, 'detalhe' => array] (detalhe estruturado, só primitivos).
 */
final class ResultadoVerificacaoTrancamento
{
    /**
     * @param  list<array{mensagem: string, detalhe: array<string, mixed>}>  $bloqueios
     * @param  list<array{mensagem: string, detalhe: array<string, mixed>}>  $avisos
     */
    public function __construct(
        public readonly array $bloqueios = [],
        public readonly array $avisos = [],
    ) {
    }

    public function bloqueia(): bool
    {
        return $this->bloqueios !== [];
    }

    public function juntar(self $outro): self
    {
        return new self([...$this->bloqueios, ...$outro->bloqueios], [...$this->avisos, ...$outro->avisos]);
    }

    /** @return array{bloqueios: list<array>, avisos: list<array>} */
    public function toArray(): array
    {
        return ['bloqueios' => $this->bloqueios, 'avisos' => $this->avisos];
    }
}
