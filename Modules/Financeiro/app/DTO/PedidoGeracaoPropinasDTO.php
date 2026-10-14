<?php

namespace Modules\Financeiro\DTO;

use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;
use Modules\Financeiro\Enums\AmbitoGeracaoPropinas;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;

/**
 * O que gerar: ano lectivo (sempre explícito), âmbito, origem e, opcionalmente, o plano a que a geração
 * se limita (L9) e os períodos anteriores ao mês da matrícula (com motivo, só manual). A origem
 * RETROACTIVA nunca vem no pedido: atribui-se por período, aos anteriores à matrícula.
 */
final class PedidoGeracaoPropinasDTO
{
    public function __construct(
        public readonly int $ano_lectivo_id,
        public readonly AmbitoGeracaoPropinas $ambito,
        public readonly OrigemGeracaoPropina $origem,
        public readonly ?int $alvo_id = null,
        public readonly ?string $matricula_numero = null,
        public readonly ?int $plano_propina_id = null,
        public readonly bool $incluir_periodos_anteriores = false,
        public readonly ?string $motivo_geracao = null,
    ) {
        if ($origem === OrigemGeracaoPropina::RETROACTIVA) {
            throw new InvalidArgumentException('A origem Retroactiva atribui-se por período, nunca ao pedido.');
        }

        if ($incluir_periodos_anteriores && trim((string) $motivo_geracao) === '') {
            throw new InvalidArgumentException('Incluir períodos anteriores exige o motivo da geração.');
        }

        if ($incluir_periodos_anteriores && $origem !== OrigemGeracaoPropina::MANUAL) {
            throw new InvalidArgumentException('Só a geração manual pode incluir períodos anteriores.');
        }

        if (in_array($ambito, [AmbitoGeracaoPropinas::TURMA, AmbitoGeracaoPropinas::NIVEL], true) && $alvo_id === null) {
            throw new InvalidArgumentException('O âmbito turma ou nível exige a turma ou o nível.');
        }

        if ($ambito === AmbitoGeracaoPropinas::PLANO && $plano_propina_id === null) {
            throw new InvalidArgumentException('O âmbito «todas as do plano» exige o plano.');
        }

        if ($ambito === AmbitoGeracaoPropinas::MATRICULA && trim((string) $matricula_numero) === '') {
            throw new InvalidArgumentException('O âmbito matrícula exige o número de registo da matrícula.');
        }
    }

    public static function fromRequest(FormRequest $request): self
    {
        $dados = $request->validated();
        $ambito = AmbitoGeracaoPropinas::from($dados['ambito']);
        $incluir = (bool) ($dados['incluir_periodos_anteriores'] ?? false);

        return new self(
            ano_lectivo_id: (int) $dados['ano_lectivo_id'],
            ambito: $ambito,
            origem: OrigemGeracaoPropina::MANUAL,
            alvo_id: match ($ambito) {
                AmbitoGeracaoPropinas::TURMA => (int) $dados['turma_id'],
                AmbitoGeracaoPropinas::NIVEL => (int) $dados['nivel_academico_id'],
                default => null,
            },
            matricula_numero: $ambito === AmbitoGeracaoPropinas::MATRICULA ? trim((string) $dados['matricula_numero']) : null,
            plano_propina_id: isset($dados['plano_propina_id']) ? (int) $dados['plano_propina_id'] : null,
            incluir_periodos_anteriores: $incluir,
            motivo_geracao: $incluir ? trim((string) $dados['motivo_geracao']) : null,
        );
    }

    public static function automatica(int $anoLectivoId): self
    {
        return new self(
            ano_lectivo_id: $anoLectivoId,
            ambito: AmbitoGeracaoPropinas::TODAS,
            origem: OrigemGeracaoPropina::COMANDO,
        );
    }

    /** Q7 (F3): geração ao activar uma matrícula, identificada como na geração manual (número de registo + ano). */
    public static function daMatricula(int $anoLectivoId, string $numeroRegistoMatricula): self
    {
        return new self(
            ano_lectivo_id: $anoLectivoId,
            ambito: AmbitoGeracaoPropinas::MATRICULA,
            origem: OrigemGeracaoPropina::MATRICULA,
            matricula_numero: $numeroRegistoMatricula,
        );
    }
}
