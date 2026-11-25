<?php

namespace Modules\Frequencia\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Frequencia\Enums\EstadoPresenca;
use Modules\Matricula\Models\Matricula;

/** A presença de um aluno (matrícula) numa aula. */
class Registo extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;

    protected $table = 'frequencia_registos';

    protected $fillable = [
        'aula_id',
        'matricula_id',
        'estado',
        'estado_descricao',
        'observacao',
        'criado_por',
        'editado_por',
    ];

    protected $casts = ['estado' => EstadoPresenca::class];

    protected static function booted(): void
    {
        static::saving(function (self $registo) {
            $registo->estado_descricao = $registo->estado?->label();
        });
    }

    public function aula(): BelongsTo
    {
        return $this->belongsTo(Aula::class, 'aula_id');
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(Matricula::class);
    }

    public function justificacao(): HasOne
    {
        return $this->hasOne(Justificacao::class, 'registo_id');
    }

    public function historico(): HasMany
    {
        return $this->hasMany(RegistoHistorico::class, 'registo_id');
    }
}
