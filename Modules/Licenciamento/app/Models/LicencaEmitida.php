<?php

namespace Modules\Licenciamento\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Licenciamento\Enums\EstadoComercial;
use Modules\Licenciamento\Enums\Modalidade;

/** Licença emitida: snapshot comercial dos direitos + token para descarga. Global; `id` é o `jti` do token. */
class LicencaEmitida extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'licenciamento_licencas';

    protected $guarded = [];

    // O token só sai explicitamente (descarga); nunca em toArray()/JSON.
    protected $hidden = ['token'];

    protected $casts = [
        'ligacao_obrigatoria' => 'boolean',
        'modalidade' => Modalidade::class,
        'estado_comercial' => EstadoComercial::class,
        'modulos' => 'array',
        'emails_bloqueia_ao_exceder' => 'boolean',
        'emitida_em' => 'immutable_datetime',
        'inicio' => 'immutable_datetime',
        'expira_em' => 'immutable_datetime',
    ];

    /** Histórico por ordem cronológica (o id desempata eventos no mesmo segundo). */
    public function eventos(): HasMany
    {
        return $this->hasMany(EventoDeLicenca::class, 'licenca_id')->orderBy('created_at')->orderBy('id');
    }

    protected static function booted(): void
    {
        static::saving(function (self $licenca) {
            $licenca->modalidade_descricao = $licenca->modalidade->label();
            $licenca->estado_comercial_descricao = $licenca->estado_comercial->label();
        });
    }
}
