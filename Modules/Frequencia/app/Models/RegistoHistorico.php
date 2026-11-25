<?php

namespace Modules\Frequencia\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Frequencia\Enums\EstadoPresenca;
use Modules\Frequencia\Enums\EventoFrequencia;
use Modules\Usuario\Models\User;

/** Só se insere: cada marcação, alteração, justificação e remoção de justificação fica registada. */
class RegistoHistorico extends Model
{
    use PertenceAoTenant;

    public const UPDATED_AT = null;

    protected $table = 'frequencia_registos_historico';

    protected $fillable = ['registo_id', 'evento', 'evento_descricao', 'estado_anterior', 'estado_novo', 'motivo', 'alterado_por'];

    protected $casts = [
        'evento' => EventoFrequencia::class,
        'estado_anterior' => EstadoPresenca::class,
        'estado_novo' => EstadoPresenca::class,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $linha) {
            $linha->evento_descricao = $linha->evento?->label();
        });
    }

    public function registo(): BelongsTo
    {
        return $this->belongsTo(Registo::class, 'registo_id');
    }

    public function alteradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'alterado_por');
    }
}
