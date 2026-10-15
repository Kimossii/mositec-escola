<?php

namespace Modules\Financeiro\DTO;

use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;

/** Motivo obrigatório de qualquer anulação (pagamento, utilização de crédito, propina). */
final class MotivoDeAnulacaoDTO
{
    public const MINIMO = 5;

    public const MAXIMO = 500;

    public readonly string $motivo;

    public function __construct(string $motivo)
    {
        $motivo = trim($motivo);
        $tamanho = mb_strlen($motivo);

        if ($tamanho < self::MINIMO || $tamanho > self::MAXIMO) {
            throw new InvalidArgumentException('O motivo da anulação tem de ter entre ' . self::MINIMO . ' e ' . self::MAXIMO . ' caracteres.');
        }

        $this->motivo = $motivo;
    }

    public static function fromRequest(FormRequest $request): self
    {
        return new self((string) $request->validated('motivo'));
    }
}
