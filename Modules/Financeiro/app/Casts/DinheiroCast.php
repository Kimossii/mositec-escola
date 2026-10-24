<?php

namespace Modules\Financeiro\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Modules\Financeiro\Support\Dinheiro;

class DinheiroCast implements CastsAttributes, SerializesCastableAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Dinheiro
    {
        return $value === null ? null : Dinheiro::deUnidadesMenores((int) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        return match (true) {
            $value === null => null,
            $value instanceof Dinheiro => $value->unidadesMenores(),
            is_int($value) => Dinheiro::deUnidadesMenores($value)->unidadesMenores(),
            default => throw new InvalidArgumentException('O valor monetário tem de ser Dinheiro ou um inteiro em unidades menores.'),
        };
    }

    /**
     * Na serialização (toArray/JSON/Inertia) o dinheiro sai como inteiro em unidades menores.
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        return $value?->unidadesMenores();
    }
}
