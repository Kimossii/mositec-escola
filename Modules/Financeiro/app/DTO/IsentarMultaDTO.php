<?php

namespace Modules\Financeiro\DTO;

use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Support\Dinheiro;

/**
 * Isenção/redução manual de uma multa (spec Propinas §15.4, permissão `propina.isentar-multa`). O `novo_valor`
 * tem de ficar entre o já pago e o valor actual (validação final na Action, sob bloqueio); `novo_valor == valor_pago`
 * isenta, senão reduz.
 */
final class IsentarMultaDTO
{
    public const MOTIVO_MINIMO = 5;

    public const MOTIVO_MAXIMO = 500;

    public readonly string $motivo;

    public function __construct(
        public readonly Dinheiro $novoValor,
        string $motivo,
    ) {
        $motivo = trim($motivo);
        $tamanho = mb_strlen($motivo);

        if ($tamanho < self::MOTIVO_MINIMO || $tamanho > self::MOTIVO_MAXIMO) {
            throw new InvalidArgumentException('O motivo da isenção/redução tem de ter entre ' . self::MOTIVO_MINIMO . ' e ' . self::MOTIVO_MAXIMO . ' caracteres.');
        }

        $this->motivo = $motivo;
    }

    public static function fromRequest(FormRequest $request): self
    {
        $moeda = app(MoedaDoTenant::class)->atual();

        return new self(
            Dinheiro::deDecimal((string) $request->validated('novo_valor'), $moeda),
            (string) $request->validated('motivo'),
        );
    }
}
