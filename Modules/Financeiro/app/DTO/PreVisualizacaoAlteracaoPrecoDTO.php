<?php

namespace Modules\Financeiro\DTO;

use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Support\RotuloDePeriodo;

/**
 * O que a alteração de preço faria (spec §14 "Pré-visualização obrigatória"). A mesma estrutura serve a confirmação:
 * a Action recalcula-a sobre o estado bloqueado e compara a impressão digital.
 */
final class PreVisualizacaoAlteracaoPrecoDTO
{
    public const GARANTIA_OUTROS_ANOS = 'Nenhuma propina de outros anos lectivos será alterada.';

    /**
     * @param  array{id: int, nome: ?string}  $anoLectivo
     * @param  list<array<string, mixed>>  $planos
     * @param  list<array<string, mixed>>  $afectadas
     * @param  list<array<string, mixed>>  $inalteradas
     * @param  list<array<string, mixed>>  $reassociadasSemAjuste  Em Aberto/Parciais que já têm o valor novo e só mudam de plano (D6)
     * @param  array<string, int>  $totais
     * @param  array<string, mixed>|null  $avisoValoresDiferentes
     */
    public function __construct(
        public readonly ModoAlteracaoPreco $modo,
        public readonly string $mesEfeito,
        public readonly bool $inLoco,
        public readonly array $anoLectivo,
        public readonly int $propinasDeOutrosAnos,
        public readonly int $valorAnterior,
        public readonly int $valorNovo,
        public readonly ?string $nomePlanoNovo,
        public readonly array $planos,
        public readonly array $afectadas,
        public readonly array $inalteradas,
        public readonly array $reassociadasSemAjuste,
        public readonly array $totais,
        public readonly ?array $avisoValoresDiferentes,
        public readonly string $impressaoDigital,
    ) {
    }

    /** M2 (fases F4): com MANTER e alunos já gerados ao valor antigo, a confirmação exige reconhecer os dois valores. */
    public function exigeConfirmacaoValoresDiferentes(): bool
    {
        return $this->modo === ModoAlteracaoPreco::MANTER_GERADAS
            && (int) ($this->avisoValoresDiferentes['matriculas_valor_antigo'] ?? 0) > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'modo' => $this->modo->value,
            'modo_descricao' => $this->modo->label(),
            'mes_efeito' => substr($this->mesEfeito, 0, 7),
            'mes_efeito_rotulo' => RotuloDePeriodo::competencia((int) substr($this->mesEfeito, 0, 4), (int) substr($this->mesEfeito, 5, 2)),
            'in_loco' => $this->inLoco,
            'ano_lectivo' => $this->anoLectivo,
            'garantia_outros_anos' => self::GARANTIA_OUTROS_ANOS,
            'propinas_de_outros_anos' => $this->propinasDeOutrosAnos,
            'valor_anterior' => $this->valorAnterior,
            'valor_novo' => $this->valorNovo,
            'nome_plano_novo' => $this->nomePlanoNovo,
            'planos' => $this->planos,
            'afectadas' => $this->afectadas,
            'inalteradas' => $this->inalteradas,
            'reassociadas_sem_ajuste' => $this->reassociadasSemAjuste,
            'totais' => $this->totais,
            'aviso_valores_diferentes' => $this->avisoValoresDiferentes,
            'exige_confirmacao_valores_diferentes' => $this->exigeConfirmacaoValoresDiferentes(),
            'impressao_digital' => $this->impressaoDigital,
        ];
    }
}
