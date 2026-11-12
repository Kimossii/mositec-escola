<?php

namespace Modules\Avaliacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Turma\Models\NivelAcademico;

/** A regra a que uma classe pertence num ano lectivo: numérica (`regra_id`) ou qualitativa (`regra_qualitativa_id`). */
class AvaliacaoRegraNivel extends Model
{
    use PertenceAoTenant;

    protected $table = 'avaliacao_regra_niveis';

    protected $fillable = ['ano_lectivo_id', 'nivel_academico_id', 'regra_id', 'regra_qualitativa_id'];

    public function nivelAcademico(): BelongsTo
    {
        return $this->belongsTo(NivelAcademico::class);
    }

    public function regra(): BelongsTo
    {
        return $this->belongsTo(AvaliacaoRegra::class, 'regra_id');
    }

    public function regraQualitativa(): BelongsTo
    {
        return $this->belongsTo(AvaliacaoRegraQualitativa::class, 'regra_qualitativa_id');
    }
}
