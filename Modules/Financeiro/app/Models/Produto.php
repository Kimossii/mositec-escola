<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Enums\Estado;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Core\Traits\SincronizaEstadoDescricao;
use Modules\Financeiro\Casts\DinheiroCast;
use Modules\Financeiro\Enums\MomentoObrigatorio;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'obrigatorio',
        'momento_obrigatorio',
        'apenas_alunos_novos',
        'controla_stock',
        'stock_quantidade',
        'tem_variantes',
        'estado',
    ];

    protected $hidden = ['tenant_id', 'criado_por', 'editado_por'];

    protected $attributes = [
        'estado' => 1,
    ];

    protected $casts = [
        'preco' => DinheiroCast::class,
        'obrigatorio' => 'boolean',
        'momento_obrigatorio' => MomentoObrigatorio::class,
        'apenas_alunos_novos' => 'boolean',
        'controla_stock' => 'boolean',
        'stock_quantidade' => 'integer',
        'tem_variantes' => 'boolean',
        'estado' => 'integer',
    ];

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('estado', Estado::ATIVO->value);
    }

    protected static function booted(): void
    {
        static::saving(function (self $item) {
            $item->momento_obrigatorio_descricao = $item->momento_obrigatorio?->label();
        });
    }

    public function variantes(): HasMany
    {
        return $this->hasMany(ProdutoVariante::class)->orderBy('nome');
    }
}
