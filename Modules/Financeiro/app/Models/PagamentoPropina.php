<?php

namespace Modules\Financeiro\Models;

use Modules\Financeiro\Support\InvalidaTotaisFinanceiros;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Financeiro\Casts\DinheiroCast;

/**
 * Parte de um pagamento atribuída a uma propina (spec Pagamentos §3). Só se cria (RegistarPagamentoAction);
 * conta para o valor pago apenas enquanto o pagamento estiver Confirmado.
 */
class PagamentoPropina extends Model
{
    use PertenceAoTenant;
    use InvalidaTotaisFinanceiros;

    public const UPDATED_AT = null;

    protected $table = 'pagamento_propinas';

    protected $fillable = ['pagamento_id', 'propina_id', 'valor'];

    protected $hidden = ['tenant_id'];

    protected $casts = [
        'pagamento_id' => 'integer',
        'propina_id' => 'integer',
        'valor' => DinheiroCast::class,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $distribuicao) {
            if (($distribuicao->valor?->unidadesMenores() ?? 0) <= 0) {
                throw new LogicException('O valor distribuído a uma propina tem de ser maior que zero.');
            }

            $pagamento = Pagamento::query()->find($distribuicao->pagamento_id);
            $propina = Propina::query()->find($distribuicao->propina_id);

            if ($pagamento === null || $propina === null) {
                throw new LogicException('O pagamento e a propina têm de existir no tenant da distribuição.');
            }

            if ((int) $propina->matricula_id !== (int) $pagamento->matricula_id) {
                throw new LogicException('Um pagamento só se distribui por propinas da sua matrícula.');
            }
        });

        static::updating(function () {
            throw new LogicException('Uma distribuição de pagamento nunca se edita: anula-se o pagamento.');
        });

        static::deleting(function () {
            throw new LogicException('Uma distribuição de pagamento nunca se elimina: anula-se o pagamento.');
        });
    }

    public function pagamento(): BelongsTo
    {
        return $this->belongsTo(Pagamento::class, 'pagamento_id');
    }

    public function propina(): BelongsTo
    {
        return $this->belongsTo(Propina::class, 'propina_id');
    }
}
