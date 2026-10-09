<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Enums\Estado;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Core\Traits\SincronizaEstadoDescricao;
use Modules\Financeiro\Casts\DinheiroCast;

/**
 * Prestação do catálogo da escola (sem stock). Entidade distinta de Produto (sem tabela genérica).
 * O preço é o preço ACTUAL do catálogo: operações futuras copiam-no (snapshot).
 */
class Servico extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;
    use SincronizaEstadoDescricao;

    protected $table = 'servicos';

    protected $fillable = [
        'nome',
        'descricao',
        'codigo',
        'preco',
        'estado',
    ];

    protected $hidden = ['tenant_id', 'criado_por', 'editado_por'];

    protected $attributes = [
        'estado' => 1,
    ];

    protected $casts = [
        'preco' => DinheiroCast::class,
        'estado' => 'integer',
    ];

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('estado', Estado::ATIVO->value);
    }
}
