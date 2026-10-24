<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Financeiro\Casts\DataCast;
use Modules\Financeiro\Casts\DinheiroCast;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Usuario\Models\User;

/**
 * Registo de uma alteração de preço de um plano com propinas (spec Propinas §14): a escolha, o mês de efeito,
 * o motivo e o autor. Só se cria: reverter é uma nova alteração, com o seu motivo.
 */
class AlteracaoPrecoPlano extends Model
{
    use PertenceAoTenant;

    protected $table = 'alteracoes_preco_plano';

    protected $fillable = [
        'ano_lectivo_id',
        'plano_propina_id',
        'plano_novo_id',
        'modo',
        'valor_anterior',
        'valor_novo',
        'mes_efeito',
        'motivo',
        'total_propinas_afectadas',
        'impressao_digital',
    ];

    /** Não persistida: Em Aberto/Parciais que só mudaram de plano (D6), para a mensagem final. */
    public int $totalReassociadasSemAjuste = 0;

    protected $hidden = ['tenant_id'];

    protected $casts = [
        'ano_lectivo_id' => 'integer',
        'plano_propina_id' => 'integer',
        'plano_novo_id' => 'integer',
        'modo' => ModoAlteracaoPreco::class,
        'valor_anterior' => DinheiroCast::class,
        'valor_novo' => DinheiroCast::class,
        'mes_efeito' => DataCast::class,
        'total_propinas_afectadas' => 'integer',
        'criado_por' => 'integer',
    ];

    protected static function booted(): void
    {
        // Null-safe: a matriz de isolamento grava models vazios em cru e espera TenantNaoResolvido (lançado no
        // `creating` da trait, registado antes deste).
        static::saving(function (self $alteracao) {
            $alteracao->modo_descricao = $alteracao->modo?->label();
        });

        // Sem `editado_por` (nunca há edição): não usa RegistaAutoria.
        static::creating(function (self $alteracao) {
            $alteracao->criado_por ??= auth()->id();
        });

        static::updating(function () {
            throw new LogicException('Uma alteração de preço registada nunca muda: reverter é uma nova alteração, com o seu motivo.');
        });

        static::deleting(function () {
            throw new LogicException('Uma alteração de preço registada nunca é eliminada.');
        });
    }

    public function plano(): BelongsTo
    {
        return $this->belongsTo(PlanoPropina::class, 'plano_propina_id');
    }

    public function planoNovo(): BelongsTo
    {
        return $this->belongsTo(PlanoPropina::class, 'plano_novo_id');
    }

    public function anoLectivo(): BelongsTo
    {
        return $this->belongsTo(AnoLectivo::class, 'ano_lectivo_id')->withTrashed();
    }

    public function ajustes(): HasMany
    {
        return $this->hasMany(PropinaAjuste::class, 'alteracao_preco_id')->orderBy('id');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }
}
