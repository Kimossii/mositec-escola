<?php

namespace Modules\Matricula\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Matricula\Database\Factories\MatriculaRegistoSequenciaFactory;

class MatriculaRegistoSequencia extends Model
{
    protected $table = 'matricula_registo_sequencias';

    protected $fillable = ['ano', 'ultimo_numero'];
}
