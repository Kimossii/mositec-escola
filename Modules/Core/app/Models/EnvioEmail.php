<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Comunicacao\Email\CanalEnvioEmail;
use Modules\Core\Comunicacao\Email\CategoriaEmail;
use Modules\Core\Comunicacao\Email\EstadoEnvioEmail;
use Modules\Core\Tenancy\PertenceAoTenant;

/** Tentativa de envio de um email de uma escola. Só se escreve por RegistoDeEnvios; é histórico, nunca se edita. */
class EnvioEmail extends Model
{
    use PertenceAoTenant;

    public const UPDATED_AT = null;

    protected $table = 'envios_email';

    protected $fillable = [
        'notificacao',
        'categoria',
        'estado',
        'canal',
        'destinatario_user_id',
        'destinatario_mascarado',
        'erro',
    ];

    protected $casts = [
        'categoria' => CategoriaEmail::class,
        'estado' => EstadoEnvioEmail::class,
        'canal' => CanalEnvioEmail::class,
    ];

    protected static function booted(): void
    {
        static::saving(function (EnvioEmail $envio) {
            $envio->estado_descricao = $envio->estado->label();
            $envio->canal_descricao = ($envio->canal ?? CanalEnvioEmail::CENTRAL)->label();
        });
    }
}
