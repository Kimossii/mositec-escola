<?php

namespace Modules\Avaliacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Avaliacao\Enums\EstadoNota;
use Modules\Core\Tenancy\PertenceAoTenant;

/** Registo imutável de cada lançamento ou alteração de uma nota, incluindo os feitos depois do prazo. */
class AvaliacaoNotaHistorico extends Model
{
    use PertenceAoTenant;

    protected $table = 'avaliacao_notas_historico';

    protected $fillable = ['nota_id', 'valor_anterior', 'valor_novo', 'estado_anterior', 'estado_novo', 'fora_do_prazo', 'motivo', 'criado_por'];

    protected $casts = [
        'valor_anterior' => 'decimal:2',
        'valor_novo' => 'decimal:2',
        'estado_anterior' => EstadoNota::class,
        'estado_novo' => EstadoNota::class,
        'fora_do_prazo' => 'boolean',
    ];

    public function nota(): BelongsTo
    {
        return $this->belongsTo(AvaliacaoNota::class, 'nota_id');
    }
}
