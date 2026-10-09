<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Financeiro\Support\TaxaCambio;

/**
 * Câmbio por defeito, igual para todas as escolas (sem tenant): 1 USD = taxa unidades da moeda.
 * No máximo uma linha por dia por moeda; alimentado por comando e, no futuro, por uma API.
 */
class CambioPlataforma extends Model
{
    protected $table = 'cambios_plataforma';

    protected $fillable = [
        'moeda_cotada',
        'moeda_base',
        'data',
        'taxa',
        'fonte',
    ];

    protected $casts = [
        'data' => 'date:Y-m-d',
        'taxa' => 'integer',
    ];

    public function taxaCambio(): TaxaCambio
    {
        return TaxaCambio::deMicros($this->taxa);
    }
}
