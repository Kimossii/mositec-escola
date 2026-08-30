<?php

namespace Modules\Usuario\Models;

use Illuminate\Database\Eloquent\Model;

class MatriculaSequencia extends Model
{
    protected $table = 'matricula_sequencias';

    protected $fillable = ['ano', 'ultimo_numero'];
}
