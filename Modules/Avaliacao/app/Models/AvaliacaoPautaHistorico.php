<?php

namespace Modules\Avaliacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Avaliacao\Enums\EventoDaPauta;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Usuario\Models\User;

/** Registo de cada aprovação e reabertura de uma pauta: só se acrescenta, nunca se edita nem se elimina. */
class AvaliacaoPautaHistorico extends Model
{
    use PertenceAoTenant;

    protected $table = 'avaliacao_pauta_historico';

    protected $fillable = ['pauta_id', 'evento', 'evento_descricao', 'versao', 'motivo', 'ocorrido_em', 'criado_por'];

    protected $casts = [
        'evento' => EventoDaPauta::class,
        'versao' => 'integer',
        'ocorrido_em' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $registo) {
            $registo->evento_descricao = $registo->evento?->label();
        });
        static::updating(fn () => throw new LogicException('O histórico da pauta só se acrescenta.'));
        static::deleting(fn () => throw new LogicException('O histórico da pauta só se acrescenta.'));
    }

    public function pauta(): BelongsTo
    {
        return $this->belongsTo(AvaliacaoPauta::class, 'pauta_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }
}
