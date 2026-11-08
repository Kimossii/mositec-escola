<?php

namespace Modules\Avaliacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Avaliacao\Enums\Progressao;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;

/** Regra qualitativa (Creche, Pré-Escolar) de um ano lectivo: que escala usa e como se decide a progressão. As classes ligam-se a ela. */
class AvaliacaoRegraQualitativa extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;

    protected $table = 'avaliacao_regras_qualitativas';

    protected $fillable = ['ano_lectivo_id', 'nome', 'escala_id', 'progressao', 'progressao_descricao', 'criado_por', 'editado_por'];

    protected $casts = ['progressao' => Progressao::class];

    protected static function booted(): void
    {
        static::saving(function (self $regra) {
            $regra->progressao_descricao = ($regra->progressao ?? Progressao::AUTOMATICA)->label();
        });
    }

    public function anoLectivo(): BelongsTo
    {
        return $this->belongsTo(AnoLectivo::class);
    }

    /** As classes ligadas a esta regra. */
    public function niveis(): HasMany
    {
        return $this->hasMany(AvaliacaoRegraNivel::class, 'regra_qualitativa_id');
    }

    public function escala(): BelongsTo
    {
        return $this->belongsTo(AvaliacaoEscala::class, 'escala_id');
    }
}
