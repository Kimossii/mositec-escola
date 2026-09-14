<?php

namespace Modules\Matricula\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Aluno\Models\Aluno;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\User;
// use Modules\Matricula\Database\Factories\MatriculaFactory;

class Matricula extends Model
{
    use SoftDeletes;

    protected $table = 'matriculas';

    protected $fillable = [
        'aluno_id',
        'turma_id',
        'ano_lectivo_id',
        'numero_registo_matricula',
        'data_matricula',
        'estado',
        'observacoes',
        'criado_por',
        'editado_por',
    ];

    protected $casts = [
        'data_matricula' => 'date',
        'estado' => EstadoMatriculaEnum::class,
    ];

    public function aluno(): BelongsTo
    {
        return $this->belongsTo(Aluno::class);
    }

    public function turma(): BelongsTo
    {
        return $this->belongsTo(Turma::class);
    }

    public function anoLectivo(): BelongsTo
    {
        return $this->belongsTo(AnoLectivo::class);
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
