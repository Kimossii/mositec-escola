<?php

namespace Modules\PlanoCurricular\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Core\Traits\SincronizaEstadoDescricao;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\User;

class PlanoCurricular extends Model
{
    use RegistaAutoria;
    use SincronizaEstadoDescricao;

    protected $table = 'planos_curriculares';

    protected $fillable = [
        'estabelecimento_id',
        'curso_id',
        'codigo',
        'nome',
        'descricao',
        'estado',
        'estado_descricao',
        'criado_por',
        'editado_por',
    ];

    protected $attributes = [
        'estado' => 1,
    ];

    protected $casts = [
        'estado' => 'integer',
    ];

    public function estabelecimento(): BelongsTo
    {
        return $this->belongsTo(Estabelecimento::class);
    }

    public function curso(): BelongsTo
    {
        return $this->belongsTo(Curso::class);
    }

    public function disciplinas(): HasMany
    {
        return $this->hasMany(PlanoCurricularDisciplina::class)->orderBy('ordem');
    }

    public function anosLectivos(): HasMany
    {
        return $this->hasMany(PlanoCurricularAnoLectivo::class);
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
