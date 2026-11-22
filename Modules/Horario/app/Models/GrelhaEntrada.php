<?php

namespace Modules\Horario\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\Horario;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Disciplina\Models\Disciplina;
use Modules\Horario\Enums\DiaSemana;
use Modules\Infraestrutura\Models\Sala;
use Modules\Turma\Models\Turma;

/**
 * Uma linha da grelha semanal: a turma tem esta disciplina neste dia e tempo, durante a vigência. O docente não
 * se guarda: resolve-se pela atribuição em vigor. Alterar fecha a linha e cria outra (histórico).
 */
class GrelhaEntrada extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;
    use SoftDeletes;

    protected $table = 'horario_grelha_entradas';

    protected $fillable = [
        'ano_lectivo_id',
        'turma_id',
        'dia_semana',
        'dia_semana_descricao',
        'horario_id',
        'disciplina_id',
        'sala_id',
        'vigencia_inicio',
        'vigencia_fim',
        'criado_por',
        'editado_por',
    ];

    protected $casts = [
        'dia_semana' => DiaSemana::class,
        'vigencia_inicio' => 'date',
        'vigencia_fim' => 'date',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $entrada) {
            $entrada->dia_semana_descricao = $entrada->dia_semana?->label();
        });
    }

    public function turma(): BelongsTo
    {
        return $this->belongsTo(Turma::class);
    }

    public function horario(): BelongsTo
    {
        return $this->belongsTo(Horario::class);
    }

    public function disciplina(): BelongsTo
    {
        return $this->belongsTo(Disciplina::class);
    }

    public function sala(): BelongsTo
    {
        return $this->belongsTo(Sala::class);
    }

    /** Vigente na data: início ≤ data e (fim nulo ou fim ≥ data); extremos inclusivos, ao dia. */
    public function scopeEmVigor(Builder $query, CarbonInterface $data): Builder
    {
        $dia = $data->toDateString();

        return $query->whereDate('vigencia_inicio', '<=', $dia)
            ->where(fn (Builder $q) => $q->whereNull('vigencia_fim')->orWhereDate('vigencia_fim', '>=', $dia));
    }

    /** Vigência que se sobrepõe ao intervalo [inicio, fim] (fim nulo = em aberto); extremos inclusivos. */
    public function scopeSobrepoe(Builder $query, string $inicio, ?string $fim): Builder
    {
        return $query
            ->when($fim !== null, fn (Builder $q) => $q->whereDate('vigencia_inicio', '<=', $fim))
            ->where(fn (Builder $q) => $q->whereNull('vigencia_fim')->orWhereDate('vigencia_fim', '>=', $inicio));
    }
}
