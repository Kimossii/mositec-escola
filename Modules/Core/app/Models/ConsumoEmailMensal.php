<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\PertenceAoTenant;

/** Quantos emails opcionais a escola já enviou num mês (AAAA-MM, UTC). Só se escreve por ConsumoDeEmail. */
class ConsumoEmailMensal extends Model
{
    use PertenceAoTenant;

    protected $table = 'consumo_email_mensal';

    protected $fillable = ['mes', 'opcionais', 'aviso_enviado', 'bloqueio_avisado'];

    protected $casts = [
        'opcionais' => 'integer',
        'aviso_enviado' => 'boolean',
        'bloqueio_avisado' => 'boolean',
    ];
}
