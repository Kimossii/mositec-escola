<?php

namespace Modules\Financeiro\Models;

use Modules\Financeiro\Support\InvalidaTotaisFinanceiros;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Financeiro\Casts\DinheiroCast;
use Modules\Financeiro\Enums\EstadoCredito;
use Modules\Financeiro\Support\AutoriaImutavel;
use Modules\Financeiro\Support\Dinheiro;

/**
 * Excedente de um pagamento, pertencente ao ALUNO (spec Pagamentos §2.3). `valor_utilizado` e `estado`
 * (Disponível/Esgotado/Anulado) só são escritos pelo RecalcularCredito. Nunca se elimina.
 */
class Credito extends Model
{
    use PertenceAoTenant;
    use InvalidaTotaisFinanceiros;

    public const IMUTAVEIS = ['aluno_id', 'origem_pagamento_id', 'valor'];

    protected $table = 'creditos';

    protected $fillable = ['aluno_id', 'origem_pagamento_id', 'valor'];

    protected $hidden = ['tenant_id'];

    protected $attributes = [
        'estado' => 1,
        'valor_utilizado' => 0,
    ];

    protected $casts = [
        'aluno_id' => 'integer',
        'origem_pagamento_id' => 'integer',
        'valor' => DinheiroCast::class,
        'valor_utilizado' => DinheiroCast::class,
        'estado' => EstadoCredito::class,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $credito) {
            $credito->estado_descricao = $credito->estado?->label();
        });

        static::creating(function (self $credito) {
            $origem = Pagamento::query()->find($credito->origem_pagamento_id);

            if ($origem === null) {
                throw new LogicException('O pagamento de origem do crédito tem de existir no tenant.');
            }

            if ((int) $origem->aluno_id !== (int) $credito->aluno_id) {
                throw new LogicException('O crédito pertence ao aluno do pagamento que o gerou.');
            }

            if ($credito->estado !== EstadoCredito::DISPONIVEL || $credito->valor_utilizado?->unidadesMenores() !== 0) {
                throw new LogicException('Um crédito nasce Disponível e por utilizar.');
            }

            $credito->validarCoerencia();
        });

        static::updating(function (self $credito) {
            AutoriaImutavel::verificar($credito);

            $alterados = array_values(array_filter(self::IMUTAVEIS, fn (string $campo) => $credito->isDirty($campo)));

            if ($alterados !== []) {
                throw new LogicException('O aluno, a origem e o valor de um crédito nunca mudam: ' . implode(', ', $alterados) . '.');
            }

            if ($credito->getOriginal('estado') === EstadoCredito::ANULADO && $credito->isDirty('estado')) {
                throw new LogicException('Um crédito anulado é terminal.');
            }

            $credito->validarCoerencia();
        });

        static::deleting(function () {
            throw new LogicException('Um crédito nunca é eliminado.');
        });
    }

    public function aluno(): BelongsTo
    {
        return $this->belongsTo(Aluno::class, 'aluno_id');
    }

    public function origem(): BelongsTo
    {
        return $this->belongsTo(Pagamento::class, 'origem_pagamento_id');
    }

    public function utilizacoes(): HasMany
    {
        return $this->hasMany(CreditoUtilizacao::class, 'credito_id')->orderBy('id');
    }

    public function saldo(): Dinheiro
    {
        return $this->valor->subtrair($this->valor_utilizado ?? Dinheiro::deUnidadesMenores(0));
    }

    /** As mesmas regras dos CHECK de PostgreSQL. */
    private function validarCoerencia(): void
    {
        $valor = $this->valor?->unidadesMenores() ?? 0;
        $usado = $this->valor_utilizado?->unidadesMenores() ?? 0;

        if ($valor <= 0) {
            throw new LogicException('O valor de um crédito tem de ser maior que zero.');
        }

        $coerente = match ($this->estado) {
            EstadoCredito::DISPONIVEL => $usado < $valor,
            EstadoCredito::ESGOTADO => $usado === $valor,
            EstadoCredito::ANULADO => $usado === 0,
            default => false,
        };

        if (! $coerente || $usado > $valor) {
            throw new LogicException("O estado {$this->estado?->label()} do crédito não é coerente com o valor utilizado.");
        }
    }
}
