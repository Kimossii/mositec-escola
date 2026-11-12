<?php

namespace Modules\Avaliacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Matricula\Models\InscricaoDisciplina;

/** Nível qualitativo de uma criança (inscrição na área) num trimestre, com observação opcional do educador. */
class AvaliacaoQualitativa extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;

    protected $table = 'avaliacao_qualitativas';

    protected $fillable = ['inscricao_disciplina_id', 'periodo_id', 'escala_nivel_id', 'observacao', 'criado_por', 'editado_por'];

    public function inscricaoDisciplina(): BelongsTo
    {
        return $this->belongsTo(InscricaoDisciplina::class);
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(Periodo::class);
    }

    public function nivel(): BelongsTo
    {
        return $this->belongsTo(AvaliacaoEscalaNivel::class, 'escala_nivel_id');
    }

    public function historico(): HasMany
    {
        return $this->hasMany(AvaliacaoQualitativaHistorico::class, 'qualitativa_id');
    }
}
