<?php

namespace Modules\Financeiro\DTO;

use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;
use Modules\Financeiro\Support\CancelamentoDePropinas;

final class CancelarPropinaDTO
{
    public readonly string $motivo;

    public function __construct(string $motivo)
    {
        $motivo = trim($motivo);
        $tamanho = mb_strlen($motivo);

        if ($tamanho < CancelamentoDePropinas::MOTIVO_MINIMO || $tamanho > CancelamentoDePropinas::MOTIVO_MAXIMO) {
            throw new InvalidArgumentException('O motivo do cancelamento tem de ter entre '
                . CancelamentoDePropinas::MOTIVO_MINIMO . ' e ' . CancelamentoDePropinas::MOTIVO_MAXIMO . ' caracteres.');
        }

        $this->motivo = $motivo;
    }

    public static function fromRequest(FormRequest $request): self
    {
        return new self((string) $request->validated('motivo'));
    }
}
