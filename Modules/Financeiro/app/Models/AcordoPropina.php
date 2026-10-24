<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Financeiro\Enums\EstadoAcordo;

class AcordoPropina extends Model
{
    use PertenceAoTenant;

    protected $table = 'acordo_propinas';

    protected $fillable = ['acordo_id', 'propina_id', 'capital_inicial', 'multa_inicial'];

    protected $hidden = ['tenant_id'];

    protected $casts = ['capital_inicial' => 'integer', 'multa_inicial' => 'integer'];

    public function acordo(): BelongsTo
    {
        return $this->belongsTo(Acordo::class);
    }

    public function propina(): BelongsTo
    {
        return $this->belongsTo(Propina::class);
    }

    /** Ids das propinas que estão num acordo ACTIVO (multas congeladas, pagamento parcial permitido). */
    public static function idsEmAcordoActivo(): Builder
    {
        return static::query()->whereHas('acordo', fn ($a) => $a->where('estado', EstadoAcordo::ACTIVO->value))->select('propina_id');
    }
}
