<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Financeiro\Casts\DataCast;
use Modules\Financeiro\Enums\OrigemRevisaoMulta;
use Modules\Usuario\Models\User;

/**
 * Histórico de uma multa (spec Propinas §15.2): só se acrescenta (o model recusa update/delete). AUTOMATICA
 * regista cada escalão aplicado (ordem + data_referencia = L_k, sem motivo nem autor); MANUAL regista a
 * isenção/redução (motivo obrigatório, autor, sem escalão nem data_referencia).
 */
class PropinaMultaRevisao extends Model
{
    use PertenceAoTenant;

    public const UPDATED_AT = null;

    protected $table = 'propina_multa_revisoes';

    protected $fillable = [
        'propina_multa_id',
        'origem',
        'escalao_ordem',
        'valor_anterior',
        'valor_novo',
        'data_referencia',
        'motivo',
    ];

    protected $hidden = ['tenant_id'];

    protected $casts = [
        'propina_multa_id' => 'integer',
        'origem' => OrigemRevisaoMulta::class,
        'escalao_ordem' => 'integer',
        'valor_anterior' => 'integer',
        'valor_novo' => 'integer',
        'data_referencia' => DataCast::class,
        'criado_por' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $revisao) {
            $revisao->criado_por ??= auth()->id();

            if ($revisao->origem === OrigemRevisaoMulta::MANUAL) {
                if (trim((string) $revisao->motivo) === '') {
                    throw new LogicException('Uma revisão manual de multa exige o motivo.');
                }

                if ($revisao->escalao_ordem !== null || $revisao->data_referencia !== null) {
                    throw new LogicException('Uma revisão manual de multa não tem escalão nem data de referência.');
                }
            } else {
                if ($revisao->escalao_ordem === null || $revisao->data_referencia === null) {
                    throw new LogicException('Uma revisão automática de multa exige o escalão e a data de referência.');
                }

                if ($revisao->motivo !== null || $revisao->criado_por !== null) {
                    throw new LogicException('Uma revisão automática de multa não tem motivo nem autor.');
                }
            }

            if (($revisao->valor_anterior ?? -1) < 0 || ($revisao->valor_novo ?? -1) < 0) {
                throw new LogicException('Os valores de uma revisão de multa não podem ser negativos.');
            }
        });

        static::updating(function () {
            throw new LogicException('As revisões de uma multa só se acrescentam: nunca se editam.');
        });

        static::deleting(function () {
            throw new LogicException('As revisões de uma multa só se acrescentam: nunca se eliminam.');
        });
    }

    public function multa(): BelongsTo
    {
        return $this->belongsTo(PropinaMulta::class, 'propina_multa_id');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }
}
