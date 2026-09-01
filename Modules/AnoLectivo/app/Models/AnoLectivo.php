<?php

namespace Modules\AnoLectivo\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\User;

class AnoLectivo extends Model
{
    use HasFactory;
    use SoftDeletes;
    use RegistaAutoria;

    protected $table = 'ano_lectivos';

    protected $fillable = [
        'estabelecimento_id',
        'nome',
        'data_inicio',
        'data_fim',
        'estado',
        'criado_por',
        'editado_por',
    ];

    protected $casts = [
        'data_inicio' => 'date',
        'data_fim' => 'date',
        'estado' => EstadoAnoLectivo::class,
    ];

    public function estabelecimento(): BelongsTo
    {
        return $this->belongsTo(Estabelecimento::class);
    }

    public function periodos(): HasMany
    {
        return $this->hasMany(Periodo::class);
    }

    public function eventosCalendario(): HasMany
    {
        return $this->hasMany(EventoCalendario::class);
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function editadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editado_por');
    }

    public static function current(?int $estabelecimentoId = null): ?self
    {
        return static::where('estado', EstadoAnoLectivo::ATIVO)
            ->when($estabelecimentoId, fn ($query) => $query->where('estabelecimento_id', $estabelecimentoId))
            ->first();
    }

    protected static function booted(): void
    {
        static::saving(function (AnoLectivo $anoLectivo) {
            $anoLectivo->estado_descricao = $anoLectivo->estado?->label();
        });
    }
}
