<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Curso\Models\Curso;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Turma\Models\Turno;

/**
 * A que turmas um plano se aplica: nível, curso, turno (qualquer combinação) ou uma turma
 * específica (exclusiva); sem linhas, todas as do ano lectivo.
 */
class PlanoPropinaAlvo extends Model
{
    use PertenceAoTenant;

    protected $table = 'plano_propina_alvos';

    protected $fillable = [
        'plano_propina_id',
        'nivel_academico_id',
        'curso_id',
        'turno_id',
        'turma_id',
    ];

    protected $hidden = ['tenant_id'];

    protected $casts = [
        'plano_propina_id' => 'integer',
        'nivel_academico_id' => 'integer',
        'curso_id' => 'integer',
        'turno_id' => 'integer',
        'turma_id' => 'integer',
    ];

    public function plano(): BelongsTo
    {
        return $this->belongsTo(PlanoPropina::class, 'plano_propina_id');
    }

    public function nivelAcademico(): BelongsTo
    {
        return $this->belongsTo(NivelAcademico::class, 'nivel_academico_id');
    }

    public function curso(): BelongsTo
    {
        return $this->belongsTo(Curso::class, 'curso_id');
    }

    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class, 'turno_id');
    }

    public function turma(): BelongsTo
    {
        return $this->belongsTo(Turma::class, 'turma_id');
    }
}
