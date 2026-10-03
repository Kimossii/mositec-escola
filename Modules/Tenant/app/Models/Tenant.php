<?php

namespace Modules\Tenant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Core\Tenancy\TenantAtual;

/**
 * Registo de gestão do tenant. Não usa PertenceAoTenant: é o próprio tenant.
 * Os módulos da escola nunca usam este model; conhecem apenas TenantAtual.
 */
class Tenant extends Model
{
    protected $table = 'tenants';

    protected $fillable = [
        'codigo',
        'nome',
        'estado',
        'suspenso_em',
        'motivo_suspensao',
        'encerrado_em',
    ];

    protected $attributes = [
        'estado' => 1,
    ];

    protected $casts = [
        'estado' => EstadoTenant::class,
        'suspenso_em' => 'datetime',
        'encerrado_em' => 'datetime',
    ];

    public function dominios(): HasMany
    {
        return $this->hasMany(Domain::class, 'tenant_id');
    }

    public function dominioPrincipal(): HasOne
    {
        return $this->hasOne(Domain::class, 'tenant_id')->where('is_principal', true);
    }

    public function estaActivo(): bool
    {
        return $this->estado === EstadoTenant::ACTIVO;
    }

    public function estaSuspenso(): bool
    {
        return $this->estado === EstadoTenant::SUSPENSO;
    }

    public function estaEncerrado(): bool
    {
        return $this->estado === EstadoTenant::ENCERRADO;
    }

    public function paraTenantAtual(): TenantAtual
    {
        return new TenantAtual($this->id, $this->codigo, $this->nome, $this->estado);
    }

    protected static function booted(): void
    {
        static::saving(function (Tenant $tenant) {
            $tenant->estado_descricao = $tenant->estado->label();
        });

        static::updating(function (Tenant $tenant) {
            if ($tenant->isDirty('codigo')) {
                throw new LogicException('O código do tenant é imutável.');
            }
        });
    }
}
