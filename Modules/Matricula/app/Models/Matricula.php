<?php

namespace Modules\Matricula\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
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
        'estado' => 'integer',
    ];
}
