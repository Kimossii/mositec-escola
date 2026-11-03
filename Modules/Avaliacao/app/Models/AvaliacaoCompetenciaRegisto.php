<?php

namespace Modules\Avaliacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;

class AvaliacaoCompetenciaRegisto extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;

    protected $table = 'avaliacao_competencia_registos';

    protected $fillable = ['inscricao_disciplina_id', 'competencia_id', 'periodo_id', 'escala_nivel_id', 'criado_por', 'editado_por'];

    public function competencia(): BelongsTo
    {
        return $this->belongsTo(AvaliacaoCompetencia::class, 'competencia_id');
    }

    public function nivel(): BelongsTo
    {
        return $this->belongsTo(AvaliacaoEscalaNivel::class, 'escala_nivel_id');
    }
}
