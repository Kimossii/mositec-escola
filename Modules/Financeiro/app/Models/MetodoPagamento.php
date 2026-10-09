<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Enums\Estado;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Core\Traits\SincronizaEstadoDescricao;
use Modules\Financeiro\Enums\TipoMetodoPagamento;

class MetodoPagamento extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;
    use SincronizaEstadoDescricao;

    protected $table = 'metodos_pagamento';

    protected $fillable = [
        'nome',
        'tipo',
        'estado',
    ];

    protected $hidden = ['tenant_id', 'criado_por', 'editado_por'];

    protected $attributes = [
        'estado' => 1,
    ];

    protected $casts = [
        'tipo' => TipoMetodoPagamento::class,
        'estado' => 'integer',
    ];

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('estado', Estado::ATIVO->value);
    }

    protected static function booted(): void
    {
        static::saving(function (self $metodo) {
            $metodo->tipo_descricao = $metodo->tipo?->label();
        });
    }
}
