<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Financeiro\Support\TaxaCambio;

/**
 * Câmbio próprio da escola (modo manual): 1 USD = taxa unidades da moeda da escola nessa data.
 */
class Cambio extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;

    protected $table = 'cambios';

    protected $fillable = [
        'moeda_cotada',
        'moeda_base',
        'data',
        'taxa',
    ];

    protected $hidden = ['tenant_id', 'criado_por', 'editado_por'];

    protected $casts = [
        'data' => 'date:Y-m-d',
        'taxa' => 'integer',
    ];

    public function taxaCambio(): TaxaCambio
    {
        return TaxaCambio::deMicros($this->taxa);
    }
}
