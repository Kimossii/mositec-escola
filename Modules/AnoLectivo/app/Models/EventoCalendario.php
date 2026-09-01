<?php

namespace Modules\AnoLectivo\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\AnoLectivo\Enums\TipoEventoCalendario;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Core\Traits\SincronizaEstadoDescricao;

class EventoCalendario extends Model
{
    use HasFactory;
    use SincronizaEstadoDescricao;
    use RegistaAutoria;

    protected $table = 'eventos_calendario';

    protected $fillable = [
        'ano_lectivo_id',
        'titulo',
        'descricao',
        'tipo',
        'data_inicio',
        'data_fim',
        'dia_inteiro',
        'estado',
        'criado_por',
        'editado_por',
    ];

    protected $casts = [
        'data_inicio' => 'date',
        'data_fim' => 'date',
        'dia_inteiro' => 'boolean',
        'tipo' => TipoEventoCalendario::class,
    ];

    public function anoLectivo(): BelongsTo
    {
        return $this->belongsTo(AnoLectivo::class);
    }

    protected static function booted(): void
    {
        static::saving(function (EventoCalendario $evento) {
            $evento->tipo_descricao = $evento->tipo?->label();
        });
    }
}
