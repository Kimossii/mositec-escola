<?php

namespace Modules\Financeiro\Models;

use Modules\Financeiro\Support\InvalidaTotaisFinanceiros;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Financeiro\Casts\DinheiroCast;

/**
 * Parte de um pagamento atribuída a uma multa (spec Pagamentos §3). Só se cria (RegistarPagamentoAction); conta
 * para o valor pago da multa apenas enquanto o pagamento estiver Confirmado.
 */
class PagamentoMulta extends Model
{
    use PertenceAoTenant;
    use InvalidaTotaisFinanceiros;

    public const UPDATED_AT = null;

    protected $table = 'pagamento_multas';

    protected $fillable = ['pagamento_id', 'propina_multa_id', 'valor'];

    protected $hidden = ['tenant_id'];

    protected $casts = [
        'pagamento_id' => 'integer',
        'propina_multa_id' => 'integer',
        'valor' => DinheiroCast::class,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $distribuicao) {
            if (($distribuicao->valor?->unidadesMenores() ?? 0) <= 0) {
                throw new LogicException('O valor distribuído a uma multa tem de ser maior que zero.');
            }

            $pagamento = Pagamento::query()->find($distribuicao->pagamento_id);
            $multa = PropinaMulta::query()->find($distribuicao->propina_multa_id);

            if ($pagamento === null || $multa === null) {
                throw new LogicException('O pagamento e a multa têm de existir no tenant da distribuição.');
            }

            $propina = Propina::query()->find($multa->propina_id);

            if ($propina === null || (int) $propina->matricula_id !== (int) $pagamento->matricula_id) {
                throw new LogicException('Um pagamento só se distribui por multas de propinas da sua matrícula.');
            }
        });

        static::updating(function () {
            throw new LogicException('Uma distribuição de multa nunca se edita: anula-se o pagamento.');
        });

        static::deleting(function () {
            throw new LogicException('Uma distribuição de multa nunca se elimina: anula-se o pagamento.');
        });
    }

    public function pagamento(): BelongsTo
    {
        return $this->belongsTo(Pagamento::class, 'pagamento_id');
    }

    public function multa(): BelongsTo
    {
        return $this->belongsTo(PropinaMulta::class, 'propina_multa_id');
    }
}
