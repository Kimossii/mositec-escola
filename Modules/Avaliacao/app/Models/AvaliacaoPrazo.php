<?php

namespace Modules\Avaliacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;

class AvaliacaoPrazo extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;

    protected $table = 'avaliacao_prazos';

    protected $fillable = ['regra_id', 'regra_qualitativa_id', 'periodo_id', 'tipo', 'tipo_descricao', 'data_limite', 'criado_por', 'editado_por'];

    protected $casts = [
        'tipo' => TipoInstrumento::class,
        'data_limite' => 'date',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $prazo) {
            $prazo->tipo_descricao = $prazo->tipo?->label();
        });
    }

    public function regra(): BelongsTo
    {
        return $this->belongsTo(AvaliacaoRegra::class, 'regra_id');
    }

    public function regraQualitativa(): BelongsTo
    {
        return $this->belongsTo(AvaliacaoRegraQualitativa::class, 'regra_qualitativa_id');
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(Periodo::class);
    }

    public function excepcoes(): HasMany
    {
        return $this->hasMany(AvaliacaoPrazoExcepcao::class, 'prazo_id');
    }
}
