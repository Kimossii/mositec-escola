<?php

namespace Modules\PlanoCurricular\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\AnoLectivo\Models\Periodo;

class PlanoCurricularDisciplinaPeriodo extends Model
{
    protected $table = 'plano_curricular_disciplina_periodos';

    protected $fillable = [
        'plano_curricular_ano_lectivo_id',
        'plano_curricular_disciplina_id',
        'periodo_id',
    ];

    public function planoCurricularAnoLectivo(): BelongsTo
    {
        return $this->belongsTo(PlanoCurricularAnoLectivo::class);
    }

    public function planoCurricularDisciplina(): BelongsTo
    {
        return $this->belongsTo(PlanoCurricularDisciplina::class);
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(Periodo::class);
    }
}
