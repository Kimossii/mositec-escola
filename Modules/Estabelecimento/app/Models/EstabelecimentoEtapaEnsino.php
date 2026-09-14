<?php

namespace Modules\Estabelecimento\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;

class EstabelecimentoEtapaEnsino extends Model
{
    protected $table = 'estabelecimento_etapas_ensino';

    protected $fillable = [
        'estabelecimento_id',
        'etapa_ensino',
    ];

    protected $casts = [
        'etapa_ensino' => EtapaEnsinoEnum::class,
    ];

    public function estabelecimento(): BelongsTo
    {
        return $this->belongsTo(Estabelecimento::class, 'estabelecimento_id');
    }

    protected static function booted(): void
    {
        static::saving(function (self $etapa) {
            if ($etapa->etapa_ensino !== null) {
                $etapa->etapa_ensino_descricao = $etapa->etapa_ensino->label();
            }
        });
    }
}
