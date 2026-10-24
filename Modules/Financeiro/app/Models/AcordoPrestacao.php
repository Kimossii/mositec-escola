<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Financeiro\Casts\DinheiroCast;

class AcordoPrestacao extends Model
{
    use PertenceAoTenant;

    protected $table = 'acordo_prestacoes';

    protected $fillable = ['acordo_id', 'ordem', 'data_vencimento', 'valor'];

    protected $hidden = ['tenant_id'];

    protected $casts = ['ordem' => 'integer', 'data_vencimento' => 'date', 'valor' => DinheiroCast::class];
}
