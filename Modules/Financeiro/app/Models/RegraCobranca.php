<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;

class RegraCobranca extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;

    public const DEFAULTS = [
        'dia_vencimento' => 10,
        'dias_tolerancia' => 5,
        'permite_pagamento_parcial' => false,
        'permite_pagamento_antecipado' => false,
        'gerar_automaticamente' => false,
        'permite_negociacao' => false,
        'desconto_maximo_negociacao' => 0,
    ];

    protected $table = 'regras_cobranca';

    protected $fillable = [
        'dia_vencimento',
        'dias_tolerancia',
        'permite_pagamento_parcial',
        'permite_pagamento_antecipado',
        'gerar_automaticamente',
        'permite_negociacao',
        'desconto_maximo_negociacao',
    ];

    protected $hidden = ['tenant_id', 'criado_por', 'editado_por'];

    protected $casts = [
        'dia_vencimento' => 'integer',
        'dias_tolerancia' => 'integer',
        'permite_pagamento_parcial' => 'boolean',
        'permite_pagamento_antecipado' => 'boolean',
        'gerar_automaticamente' => 'boolean',
        'permite_negociacao' => 'boolean',
        'desconto_maximo_negociacao' => 'integer',
    ];

    protected static function booted(): void
    {
        // Sem negociação não há desconto: invariante garantida em qualquer caminho de escrita.
        static::saving(function (self $regra) {
            if (! $regra->permite_negociacao) {
                $regra->desconto_maximo_negociacao = 0;
            }
        });
    }

    /**
     * A regra do tenant corrente; cria-a com os defaults se ainda não existir.
     */
    public static function doTenant(): self
    {
        // firstOrCreate já usa savepoint e recupera de violações únicas concorrentes.
        return static::firstOrCreate([], static::DEFAULTS);
    }
}
