<?php

namespace Modules\PlanoCurricular\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Core\Traits\SincronizaEstadoDescricao;
use Modules\Disciplina\Models\Disciplina;
use Modules\PlanoCurricular\Enums\ComponentePlanoCurricular;
use Modules\PlanoCurricular\Enums\TipoDisciplinaPlano;
use Modules\Turma\Models\NivelAcademico;
use Modules\Usuario\Models\User;

class PlanoCurricularDisciplina extends Model
{
    use RegistaAutoria;
    use SincronizaEstadoDescricao;

    protected $table = 'plano_curricular_disciplinas';

    protected $fillable = [
        'plano_curricular_id',
        'disciplina_id',
        'nivel_academico_id',
        'carga_horaria',
        'creditos',
        'componente',
        'componente_descricao',
        'tipo',
        'tipo_descricao',
        'obrigatoria',
        'ordem',
        'estado',
        'estado_descricao',
        'criado_por',
        'editado_por',
    ];

    protected $attributes = [
        'estado' => 1,
        'tipo' => 0,
        'obrigatoria' => true,
        'ordem' => 0,
    ];

    protected $casts = [
        'estado' => 'integer',
        'componente' => ComponentePlanoCurricular::class,
        'tipo' => TipoDisciplinaPlano::class,
        'obrigatoria' => 'boolean',
        'ordem' => 'integer',
        'carga_horaria' => 'integer',
        'creditos' => 'integer',
    ];

    public function planoCurricular(): BelongsTo
    {
        return $this->belongsTo(PlanoCurricular::class);
    }

    public function disciplina(): BelongsTo
    {
        return $this->belongsTo(Disciplina::class);
    }

    public function nivelAcademico(): BelongsTo
    {
        return $this->belongsTo(NivelAcademico::class);
    }

    public function periodosPorAplicacao(): HasMany
    {
        return $this->hasMany(PlanoCurricularDisciplinaPeriodo::class);
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
        static::saving(function (PlanoCurricularDisciplina $item) {
            $item->componente_descricao = $item->componente?->label();
            $item->tipo_descricao = $item->tipo?->label();
        });
    }
}
