<?php

namespace Modules\Financeiro\DTO;

/**
 * Resultado do motor de distribuição (unidades menores). Cada linha diz quanto de crédito e de dinheiro novo vai
 * para o CAPITAL e para a MULTA de uma propina; `utilizacoes` diz de que créditos sai o crédito (para propina OU
 * multa); `excedente` é o dinheiro que vira crédito novo.
 * Invariantes: dinheiro = Σ linhas.dinheiro + Σ linhas.multa_dinheiro + excedente;
 * credito = Σ linhas.credito + Σ linhas.multa_credito = Σ utilizacoes.valor.
 */
final class PlanoDePagamentoDTO
{
    /**
     * @param  list<array{propina_id: int, multa_id: int|null, periodo: string, periodo_inicio: string, saldo_antes: int, credito: int, dinheiro: int, saldo_depois: int, multa_pendente_antes: int, multa_credito: int, multa_dinheiro: int, multa_pendente_depois: int}>  $linhas
     * @param  list<array{credito_id: int, propina_id: int|null, propina_multa_id: int|null, valor: int}>  $utilizacoes
     */
    public function __construct(
        public readonly int $dinheiro,
        public readonly int $credito,
        public readonly array $linhas,
        public readonly array $utilizacoes,
        public readonly int $excedente,
    ) {
    }

    /** @return list<array{propina_id: int, valor: int}> partes pagas com dinheiro novo ao capital (pagamento_propinas) */
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

    /** @return list<array{propina_multa_id: int, valor: int}> partes pagas com dinheiro novo à multa (pagamento_multas) */
    public function distribuicoesMulta(): array
    {
        $saida = [];

        foreach ($this->linhas as $linha) {
            if ($linha['multa_dinheiro'] > 0) {
                $saida[] = ['propina_multa_id' => $linha['multa_id'], 'valor' => $linha['multa_dinheiro']];
            }
        }

        return $saida;
    }

    public function totalDistribuido(): int
    {
        return array_sum(array_map(
            fn (array $linha) => $linha['credito'] + $linha['dinheiro'] + $linha['multa_credito'] + $linha['multa_dinheiro'],
            $this->linhas,
        ));
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
