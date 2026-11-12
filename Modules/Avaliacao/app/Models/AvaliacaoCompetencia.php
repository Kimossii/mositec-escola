<?php

namespace Modules\Avaliacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Disciplina\Models\Disciplina;
use Modules\Turma\Models\NivelAcademico;

/** Uma competência observável de uma área (ex.: «Reconhece as cores primárias»). `nivel_academico_id` nulo = todas as classes. */
class AvaliacaoCompetencia extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;

    protected $table = 'avaliacao_competencias';

    protected $fillable = ['disciplina_id', 'nivel_academico_id', 'descricao', 'ordem', 'ativa', 'criado_por', 'editado_por'];

    protected $casts = ['ordem' => 'integer', 'ativa' => 'boolean'];

    public function disciplina(): BelongsTo
    {
        return $this->belongsTo(Disciplina::class);
    }

    public function nivelAcademico(): BelongsTo
    {
        return $this->belongsTo(NivelAcademico::class);
    }
}
