<?php

namespace Modules\Financeiro\DTO;

/**
 * Resultado do motor de distribuição (unidades menores). Cada linha diz quanto de crédito e de dinheiro novo vai
 * para uma propina; `utilizacoes` diz de que créditos sai o crédito; `excedente` é o dinheiro que vira crédito novo.
 * Invariante: dinheiro = Σ linhas.dinheiro + excedente; credito = Σ linhas.credito = Σ utilizacoes.valor.
 */
final class PlanoDePagamentoDTO
{
    /**
     * @param  list<array{propina_id: int, periodo: string, periodo_inicio: string, saldo_antes: int, credito: int, dinheiro: int, saldo_depois: int}>  $linhas
     * @param  list<array{credito_id: int, propina_id: int, valor: int}>  $utilizacoes
     */
    public function __construct(
        public readonly int $dinheiro,
        public readonly int $credito,
        public readonly array $linhas,
        public readonly array $utilizacoes,
        public readonly int $excedente,
    ) {
    }

    /** @return list<array{propina_id: int, valor: int}> as partes pagas com dinheiro novo (pagamento_propinas) */
    public function distribuicoes(): array
    {
        $saida = [];

        foreach ($this->linhas as $linha) {
            if ($linha['dinheiro'] > 0) {
                $saida[] = ['propina_id' => $linha['propina_id'], 'valor' => $linha['dinheiro']];
            }
        }

        return $saida;
    }

    public function totalDistribuido(): int
    {
        return array_sum(array_map(fn (array $linha) => $linha['credito'] + $linha['dinheiro'], $this->linhas));
    }

    /** @return list<int> */
    public function propinasTocadas(): array
    {
        return array_values(array_unique(array_column($this->linhas, 'propina_id')));
    }

    /** @return list<int> */
    public function creditosUsados(): array
    {
        return array_values(array_unique(array_column($this->utilizacoes, 'credito_id')));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dinheiro' => $this->dinheiro,
            'credito' => $this->credito,
            'linhas' => $this->linhas,
            'utilizacoes' => $this->utilizacoes,
            'excedente' => $this->excedente,
            'total_distribuido' => $this->totalDistribuido(),
        ];
    }
}
