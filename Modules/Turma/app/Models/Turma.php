<?php

namespace Modules\Turma\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Core\Traits\SincronizaEstadoDescricao;
use Modules\Curso\Models\Curso;
use Modules\Usuario\Models\User;

class Turma extends Model
{
    use SoftDeletes;
    use RegistaAutoria;
    use SincronizaEstadoDescricao;

    protected $table = 'turmas';

    protected $fillable = [
        'ano_lectivo_id',
        'nivel_academico_id',
        'curso_id',
        'codigo',
        'nome',
        'turno_id',
        'estado',
        'estado_descricao',
        'criado_por',
        'editado_por',
    ];

    protected $casts = [
        'estado' => 'integer',
    ];

    public function anoLectivo(): BelongsTo
    {
        return $this->belongsTo(AnoLectivo::class);
    }
    public function nivelAcademico(): BelongsTo
    {
        return $this->belongsTo(NivelAcademico::class);
    }

    public function curso(): BelongsTo
    {
        return $this->belongsTo(Curso::class);
    }

    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class);
    }

    public function turmaSalas(): HasMany
    {
        return $this->hasMany(TurmaSala::class);
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function editadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editado_por');
    }
}
