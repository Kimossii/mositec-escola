<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Financeiro\Enums\TipoMulta;

/**
 * Degrau da multa por atraso de uma regra de cobrança. `valor`: pontos-base se PERCENTAGEM
 * (100 = 1%), unidades menores da moeda da escola se VALOR_FIXO.
 */
class EscalaoMulta extends Model
{
    use PertenceAoTenant;

    protected $table = 'escaloes_multa';

    protected $fillable = [
        'ordem',
        'dias_atraso',
        'tipo',
        'valor',
    ];

    protected $hidden = ['tenant_id'];

    protected $casts = [
        'regra_cobranca_id' => 'integer',
        'ordem' => 'integer',
        'dias_atraso' => 'integer',
        'tipo' => TipoMulta::class,
        'valor' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $escalao) {
            $escalao->tipo_descricao = $escalao->tipo?->label();
        });
    }

    public function regra(): BelongsTo
    {
        return $this->belongsTo(RegraCobranca::class, 'regra_cobranca_id');
    }
}
