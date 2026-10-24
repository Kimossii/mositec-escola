<?php

namespace Modules\Financeiro\Casts;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Data civil (sem hora) guardada SEMPRE como texto "Y-m-d". Os casts `date`/`date:Y-m-d` do Laravel só
 * gravam "Y-m-d" quando recebem esse texto: um Carbon é gravado como "Y-m-d H:i:s" (com a hora) em
 * SQLite, o que parte as comparações por texto ("2026-09-15 00:00:00" > "2026-09-15") e os índices
 * únicos sobre datas. Com este cast, SQLite e PostgreSQL guardam, comparam e indexam igual.
 */
class DataCast implements CastsAttributes, SerializesCastableAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', substr((string) $value, 0, 10));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        if (is_string($value)
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $partes) === 1
            && checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1])) {
            return $value;
        }

        throw new InvalidArgumentException("Data inválida em {$key}: use o formato AAAA-MM-DD.");
    }

    /**
     * O Laravel passa aqui o valor CRU (o texto gravado), não o resultado de get().
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : substr((string) $value, 0, 10);
    }
}
