<?php

namespace Modules\Matricula\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Usuario\Models\User;

class InscricaoDisciplina extends Model
{
    use SoftDeletes;

    protected $table = 'inscricoes_disciplinas';

    protected $fillable = [
        'matricula_id',
        'plano_curricular_disciplina_id',
        'data_inscricao',
        'data_conclusao',
        'estado',
        'observacoes',
        'criado_por',
        'editado_por',
    ];

    protected $casts = [
        'data_inscricao' => 'date',
        'data_conclusao' => 'date',
        'estado' => EstadoInscricaoDisciplinaEnum::class,
    ];

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(Matricula::class);
    }

    public function planoCurricularDisciplina(): BelongsTo
    {
        return $this->belongsTo(PlanoCurricularDisciplina::class);
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
