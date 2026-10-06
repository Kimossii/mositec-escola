<?php

namespace Modules\Matricula\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Tenancy\SequenciaPorTenant;

class MatriculaRegistoSequencia extends Model implements SequenciaPorTenant
{
    use PertenceAoTenant;

    protected $table = 'matricula_registo_sequencias';

    protected $fillable = ['ano', 'ultimo_numero'];
}
