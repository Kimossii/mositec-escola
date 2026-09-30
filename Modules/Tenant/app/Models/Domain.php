<?php

namespace Modules\Tenant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\Support\NormalizadorHost;
use Modules\Tenant\Enums\TipoDominio;

/**
 * Endereço pelo qual se chega a um tenant. Identifica o tenant, mas não é a identidade dele.
 * Não usa PertenceAoTenant: é lido antes de haver tenant resolvido.
 */
class Domain extends Model
{
    protected $table = 'domains';

    protected $fillable = [
        'dominio',
        'tipo',
        'is_principal',
    ];

    protected $attributes = [
        'tipo' => 0,
        'is_principal' => false,
    ];

    protected $casts = [
        'tipo' => TipoDominio::class,
        'is_principal' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    protected static function booted(): void
    {
        static::saving(function (Domain $dominio) {
            $dominio->dominio = NormalizadorHost::normalizar($dominio->dominio);
            $dominio->tipo_descricao = $dominio->tipo->label();
        });
    }
}
