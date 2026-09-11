<?php

namespace Modules\Turma\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Core\Traits\SincronizaEstadoDescricao;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\User;

class NivelAcademico extends Model
{
    use SoftDeletes;
    use RegistaAutoria;
    use SincronizaEstadoDescricao;

    protected $table = 'niveis_academicos';

    protected $fillable = [
        'estabelecimento_id',
        'codigo',
        'nome',
        'etapa_ensino',
        'ordem',
        'estado',
        'estado_descricao',
        'criado_por',
        'editado_por',
    ];

    protected $casts = [
        'estado' => 'integer',
        'ordem' => 'integer',
        'etapa_ensino' => EtapaEnsinoEnum::class,
    ];

    public function estabelecimento(): BelongsTo
    {
        return $this->belongsTo(Estabelecimento::class, 'estabelecimento_id');
    }

    public function turmas(): HasMany
    {
        return $this->hasMany(Turma::class);
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function editadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editado_por');
    }

    protected static function booted(): void
    {
        static::saving(function (NivelAcademico $nivelAcademico) {
            if ($nivelAcademico->etapa_ensino !== null) {
                $nivelAcademico->etapa_ensino_descricao = $nivelAcademico->etapa_ensino->label();
            }
        });
    }
}
