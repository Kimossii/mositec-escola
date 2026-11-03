<?php

namespace Modules\Avaliacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Turma\Models\NivelAcademico;

/** Regra de uma classe qualitativa (Creche, Pré-Escolar) num ano lectivo: que escala usa. */
class AvaliacaoRegraQualitativa extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;

    protected $table = 'avaliacao_regras_qualitativas';

    protected $fillable = ['ano_lectivo_id', 'nivel_academico_id', 'escala_id', 'criado_por', 'editado_por'];

    public function anoLectivo(): BelongsTo
    {
        return $this->belongsTo(AnoLectivo::class);
    }

    public function nivelAcademico(): BelongsTo
    {
        return $this->belongsTo(NivelAcademico::class);
    }

    public function escala(): BelongsTo
    {
        return $this->belongsTo(AvaliacaoEscala::class, 'escala_id');
    }
}
