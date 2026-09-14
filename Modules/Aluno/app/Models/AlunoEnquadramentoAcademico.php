<?php

namespace Modules\Aluno\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Aluno\Enums\EstadoEnquadramentoAcademicoEnum;
use Modules\Curso\Models\Curso;
use Modules\NivelAcademico\Models\NivelAcademico;

class AlunoEnquadramentoAcademico extends Model
{
    use HasFactory;

    protected $table = 'aluno_enquadramentos_academicos';

    protected $fillable = [
        'aluno_id',
        'curso_id',
        'nivel_academico_id',
        'data_inicio',
        'data_fim',
        'estado',
        'observacoes',
        'criado_por',
        'editado_por',
    ];

    protected $casts = [
        'data_inicio' => 'date',
        'data_fim' => 'date',
        'estado' => EstadoEnquadramentoAcademicoEnum::class,
    ];

    public function aluno(): BelongsTo
    {
        return $this->belongsTo(Aluno::class);
    }

    public function curso(): BelongsTo
    {
        return $this->belongsTo(Curso::class);
    }

    public function nivelAcademico(): BelongsTo
    {
        return $this->belongsTo(NivelAcademico::class);
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
