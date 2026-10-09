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
 * Bem físico do catálogo da escola. Entidade distinta de Servico (sem tabela genérica).
 * O preço é o preço ACTUAL do catálogo: operações futuras copiam-no (snapshot).
 */
class Produto extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;
    use SincronizaEstadoDescricao;

    protected $table = 'produtos';

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
