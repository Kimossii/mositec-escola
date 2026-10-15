<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Financeiro\Casts\DinheiroCast;
use Modules\Usuario\Models\User;

/**
 * Um reajuste do valor de UMA propina por uma alteração de preço (spec Propinas §14). Só se acrescenta: a cadeia
 * de ajustes explica o valor corrente (`valor = valor_original + Σ(valor_novo − valor_anterior)`).
 */
class PropinaAjuste extends Model
{
    use PertenceAoTenant;

    public const UPDATED_AT = null;

    protected $table = 'propina_ajustes';

    protected $fillable = [
        'alteracao_preco_id',
        'propina_id',
        'valor_anterior',
        'valor_novo',
        'valor_pago_no_momento',
        'plano_anterior_id',
        'plano_novo_id',
        'motivo',
    ];

    protected $hidden = ['tenant_id'];

    protected $casts = [
        'alteracao_preco_id' => 'integer',
        'propina_id' => 'integer',
        'valor_anterior' => DinheiroCast::class,
        'valor_novo' => DinheiroCast::class,
        'valor_pago_no_momento' => DinheiroCast::class,
        'plano_anterior_id' => 'integer',
        'plano_novo_id' => 'integer',
        'criado_por' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $ajuste) {
            $ajuste->criado_por ??= auth()->id();
        });

        static::updating(function () {
            throw new LogicException('Os ajustes de uma propina só se acrescentam: nunca se editam.');
        });

        static::deleting(function () {
            throw new LogicException('Os ajustes de uma propina só se acrescentam: nunca se eliminam.');
        });
    }

    public function alteracao(): BelongsTo
    {
        return $this->belongsTo(AlteracaoPrecoPlano::class, 'alteracao_preco_id');
    }

    public function propina(): BelongsTo
    {
        return $this->belongsTo(Propina::class, 'propina_id');
    }

    public function planoAnterior(): BelongsTo
    {
        return $this->belongsTo(PlanoPropina::class, 'plano_anterior_id');
    }

    public function planoNovo(): BelongsTo
    {
        return $this->belongsTo(PlanoPropina::class, 'plano_novo_id');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }
}
