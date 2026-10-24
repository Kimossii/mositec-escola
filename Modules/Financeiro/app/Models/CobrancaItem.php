<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Financeiro\Casts\DinheiroCast;

/** Linha de uma cobrança, com cópia do nome, código e preço no momento da venda. */
class CobrancaItem extends Model
{
    use PertenceAoTenant;

    protected $table = 'cobranca_itens';

    protected $fillable = [
        'cobranca_id', 'tipo', 'produto_id', 'servico_id', 'variante_id', 'nome', 'codigo', 'variante_nome',
        'preco_unitario', 'quantidade', 'total', 'stock_abatido',
    ];

    protected $hidden = ['tenant_id'];

    protected $casts = [
        'preco_unitario' => DinheiroCast::class,
        'total' => DinheiroCast::class,
        'quantidade' => 'integer',
        'stock_abatido' => 'boolean',
    ];

    public function cobranca(): BelongsTo
    {
        return $this->belongsTo(Cobranca::class);
    }
}
