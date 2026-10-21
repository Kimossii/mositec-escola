<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\PertenceAoTenant;

/** Variante de um produto (ex.: tamanho), com o seu próprio stock. */
class ProdutoVariante extends Model
{
    use PertenceAoTenant;

    protected $table = 'produto_variantes';

    protected $fillable = ['produto_id', 'nome', 'stock_quantidade'];

    protected $hidden = ['tenant_id'];

    protected $casts = ['stock_quantidade' => 'integer'];

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class);
    }
}
