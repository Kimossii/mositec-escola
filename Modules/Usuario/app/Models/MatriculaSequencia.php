<?php

namespace Modules\Usuario\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Tenancy\SequenciaPorTenant;

class MatriculaSequencia extends Model implements SequenciaPorTenant
{
    use PertenceAoTenant;

    protected $table = 'matricula_sequencias';

    protected $fillable = ['ano', 'ultimo_numero'];
}
