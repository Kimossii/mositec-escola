<?php

namespace Modules\AnoLectivo\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Core\Traits\SincronizaEstadoDescricao;

class Periodo extends Model
{
    use HasFactory;
    use SincronizaEstadoDescricao;
    use RegistaAutoria;

    protected $table = 'periodos';

    protected $fillable = [
        'ano_lectivo_id',
        'nome',
        'tipo',
        'numero',
        'data_inicio',
        'data_fim',
        'estado',
        'criado_por',
        'editado_por',
    ];

    protected $casts = [
        'data_inicio' => 'date',
        'data_fim' => 'date',
        'tipo' => TipoPeriodo::class,
    ];

    public function anoLectivo(): BelongsTo
    {
        return $this->belongsTo(AnoLectivo::class);
    }

    protected static function booted(): void
    {
        static::saving(function (Periodo $periodo) {
            $periodo->tipo_descricao = $periodo->tipo?->label();
        });
    }
}
