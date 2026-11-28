<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Comunicacao\Email\EstadoConfiguracaoEmail;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;

/** Domínio próprio verificado da escola. Só se escreve pelas Actions de Core\Actions\Email; `verificado` só vem do fornecedor. */
class DominioEmail extends Model
{
    use PertenceAoTenant, RegistaAutoria;

    protected $table = 'dominios_email';

    protected $fillable = [
        'dominio', 'id_externo', 'remetente_nome', 'remetente_local', 'registos', 'estado', 'activo', 'verificado_em', 'ultimo_erro',
    ];

    protected $casts = [
        'registos' => 'array',
        'estado' => EstadoConfiguracaoEmail::class,
        'activo' => 'boolean',
        'bloqueada_pela_plataforma' => 'boolean',
        'verificado_em' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (DominioEmail $d) {
            $d->estado ??= EstadoConfiguracaoEmail::POR_VERIFICAR;
            $d->estado_descricao = $d->estado->label();
        });
    }

    /** Verificado, activo e não bloqueado pela Plataforma: a escola envia por este domínio, sem fallback para a central. */
    public function estaEmUso(): bool
    {
        return $this->activo && ! $this->bloqueada_pela_plataforma && $this->estado === EstadoConfiguracaoEmail::VERIFICADO;
    }

    public function endereco(): string
    {
        return $this->remetente_local.'@'.$this->dominio;
    }
}
