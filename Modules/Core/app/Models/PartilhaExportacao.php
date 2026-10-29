<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Usuario\Models\User;

/** Um ficheiro de exportação partilhado, só para leitura, com um colega da escola. Termina com o ficheiro ou quando o dono revoga. */
class PartilhaExportacao extends Model
{
    use PertenceAoTenant;

    protected $table = 'pedidos_exportacao_partilhas';

    protected $fillable = ['pedido_exportacao_id', 'destinatario_id', 'partilhado_por', 'visto_em'];

    protected $casts = ['visto_em' => 'datetime'];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(PedidoExportacao::class, 'pedido_exportacao_id');
    }

    public function destinatario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'destinatario_id');
    }

    public function partilhadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partilhado_por');
    }
}
