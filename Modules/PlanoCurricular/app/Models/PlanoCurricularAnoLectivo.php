<?php

namespace Modules\PlanoCurricular\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Traits\SincronizaEstadoDescricao;
use Modules\Usuario\Models\User;

class PlanoCurricularAnoLectivo extends Model
{
    use SincronizaEstadoDescricao;

    protected $table = 'plano_curricular_anos_lectivos';

    protected $fillable = [
        'plano_curricular_id',
        'ano_lectivo_id',
        'estado',
        'estado_descricao',
        'confirmado_em',
        'confirmado_por',
        'observacoes',
    ];

    protected $attributes = [
        'estado' => 1,
    ];

    protected $casts = [
        'estado' => 'integer',
        'confirmado_em' => 'datetime',
    ];

    public function planoCurricular(): BelongsTo
    {
        return $this->belongsTo(PlanoCurricular::class);
    }

    public function anoLectivo(): BelongsTo
    {
        return $this->belongsTo(AnoLectivo::class);
    }

    public function confirmadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmado_por');
    }
}
