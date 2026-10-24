<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;

/**
 * Mês tocado por uma pausa de cobrança «não cobra». `isento` = true quando o mês fica fora da cobrança
 * (inteiro por inteiro, ou escolhido pelo utilizador quando parcial). Só existe para pausas com
 * `cobra_propina` = false.
 */
class PausaCobrancaMes extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;

    protected $table = 'pausa_cobranca_meses';

    protected $fillable = [
        'pausa_cobranca_id',
        'ano',
        'mes',
        'isento',
    ];

    protected $hidden = ['tenant_id', 'criado_por', 'editado_por'];

    protected $casts = [
        'pausa_cobranca_id' => 'integer',
        'ano' => 'integer',
        'mes' => 'integer',
        'isento' => 'boolean',
    ];

    public function pausa(): BelongsTo
    {
        return $this->belongsTo(PausaCobranca::class, 'pausa_cobranca_id');
    }
}
