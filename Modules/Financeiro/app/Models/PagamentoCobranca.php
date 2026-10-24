<?php

namespace Modules\Financeiro\Models;

use Modules\Financeiro\Support\InvalidaTotaisFinanceiros;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Financeiro\Casts\DinheiroCast;

/** Dinheiro de um pagamento aplicado a uma cobrança; só se cria (anula-se o pagamento). */
class PagamentoCobranca extends Model
{
    use PertenceAoTenant;
    use InvalidaTotaisFinanceiros;

    public const UPDATED_AT = null;

    protected $table = 'pagamento_cobrancas';

    protected $fillable = ['pagamento_id', 'cobranca_id', 'valor'];

    protected $hidden = ['tenant_id'];

    protected $casts = ['valor' => DinheiroCast::class, 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $linha) {
            if (($linha->valor?->unidadesMenores() ?? 0) <= 0) {
                throw new LogicException('O valor aplicado a uma cobrança tem de ser maior que zero.');
            }
        });
        static::updating(fn () => throw new LogicException('Uma linha de pagamento nunca se edita: anula-se o pagamento.'));
        static::deleting(fn () => throw new LogicException('Uma linha de pagamento nunca se elimina: anula-se o pagamento.'));
    }

    public function pagamento(): BelongsTo
    {
        return $this->belongsTo(Pagamento::class);
    }

    public function cobranca(): BelongsTo
    {
        return $this->belongsTo(Cobranca::class);
    }
}
