<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;

/**
 * Configuração monetária da escola (1:1 com o tenant): a moeda em que opera e se usa câmbio
 * próprio. A moeda só pode mudar enquanto não houver preços nem registos (ver MoedaDoTenant).
 */
class ConfiguracaoMonetaria extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;

    public const DEFAULTS = [
        'moeda' => 'AOA',
        'cambio_manual' => false,
    ];

    protected $table = 'configuracoes_monetarias';

    protected $fillable = [
        'moeda',
        'cambio_manual',
    ];

    protected $hidden = ['tenant_id', 'criado_por', 'editado_por'];

    protected $casts = [
        'cambio_manual' => 'boolean',
    ];

    /**
     * A configuração do tenant corrente; cria-a com os defaults se ainda não existir.
     */
    public static function doTenant(): self
    {
        return static::firstOrCreate([], static::DEFAULTS);
    }
}
