<?php

namespace Modules\Avaliacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Avaliacao\Enums\EstadoPauta;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\User;

/** Pauta final de uma turma, já aprovada. `versao` sobe a cada nova aprovação depois de uma reabertura. */
class AvaliacaoPauta extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;

    protected $table = 'avaliacao_pautas';

    protected $fillable = [
        'turma_id', 'estado', 'estado_descricao', 'versao', 'casas_decimais', 'aprovada_por', 'aprovada_em',
        'reaberta_por', 'reaberta_em', 'motivo_reabertura', 'criado_por', 'editado_por',
    ];

    protected $casts = [
        'estado' => EstadoPauta::class,
        'versao' => 'integer',
        'aprovada_em' => 'datetime',
        'reaberta_em' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $pauta) {
            $pauta->estado_descricao = $pauta->estado?->label();
        });
    }

    public function turma(): BelongsTo
    {
        return $this->belongsTo(Turma::class);
    }

    public function aprovadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprovada_por');
    }

    public function linhas(): HasMany
    {
        return $this->hasMany(AvaliacaoPautaLinha::class, 'pauta_id');
    }

    public function decisoes(): HasMany
    {
        return $this->hasMany(AvaliacaoDecisao::class, 'pauta_id');
    }
}
