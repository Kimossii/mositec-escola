<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Tenancy\SequenciaPorTenant;

class PagamentoSequencia extends Model implements SequenciaPorTenant
{
    use PertenceAoTenant;

    protected $table = 'pagamento_sequencias';

    protected $fillable = ['ano', 'ultimo_numero'];
}
