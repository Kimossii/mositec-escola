<?php

namespace Modules\Financeiro\Models;

use Modules\Financeiro\Support\InvalidaTotaisFinanceiros;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Financeiro\Casts\DinheiroCast;
use Modules\Financeiro\Enums\EstadoMulta;
use Modules\Financeiro\Enums\TipoMulta;
use Modules\Financeiro\Support\Dinheiro;

/**
 * Multa por atraso de uma propina (spec Propinas §15): 1:1 com a propina. Guarda um snapshot do escalão
 * (`tipo`, `valor_regra`) e a base de capital congelada em L₁. `valor_pago` é cache do RecalcularMulta;
 * `valor`/`estado`/`bloqueada_automatico` só mudam pelo comando de aplicação ou pela isenção/redução manual.
 */
class PropinaMulta extends Model
{
    use PertenceAoTenant;
    use InvalidaTotaisFinanceiros;
    use RegistaAutoria;

    /** Congelados na criação: a propina (1:1) e a base de capital (saldo_em(L₁)). */
    public const IMUTAVEIS = ['propina_id', 'base_capital'];

    protected $table = 'propina_multas';

    protected $fillable = [
        'propina_id',
        'escalao_ordem',
        'tipo',
        'valor_regra',
        'base_capital',
        'valor',
    ];

    protected $hidden = ['tenant_id', 'criado_por', 'editado_por'];

    protected $attributes = [
        'valor_pago' => 0,
        'estado' => 1,
        'bloqueada_automatico' => false,
    ];

    protected $casts = [
        'propina_id' => 'integer',
        'escalao_ordem' => 'integer',
        'tipo' => TipoMulta::class,
        'valor_regra' => 'integer',
        'base_capital' => DinheiroCast::class,
        'valor' => DinheiroCast::class,
        'valor_pago' => DinheiroCast::class,
        'estado' => EstadoMulta::class,
        'bloqueada_automatico' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $multa) {
            $multa->estado_descricao = $multa->estado?->label();
        });

        static::creating(function (self $multa) {
            $multa->validarCoerencia();
        });

        static::updating(function (self $multa) {
            $alterados = array_values(array_filter(self::IMUTAVEIS, fn (string $campo) => $multa->isDirty($campo)));

            if ($alterados !== []) {
                throw new LogicException('A propina e a base de capital de uma multa nunca mudam: ' . implode(', ', $alterados) . '.');
            }

            $multa->validarCoerencia();
        });

        static::deleting(function () {
            throw new LogicException('Uma multa nunca é eliminada.');
        });
    }

    public function propina(): BelongsTo
    {
        return $this->belongsTo(Propina::class, 'propina_id');
    }

    /** Revisões (histórico), da mais antiga para a mais recente. */
    public function revisoes(): HasMany
    {
        return $this->hasMany(PropinaMultaRevisao::class, 'propina_multa_id')->orderBy('id');
    }

    /** Distribuições de pagamentos sobre esta multa. */
    public function distribuicoes(): HasMany
    {
        return $this->hasMany(PagamentoMulta::class, 'propina_multa_id')->orderBy('id');
    }

    /** O que ainda está em dívida da multa: valor − valor_pago. */
    public function pendente(): Dinheiro
    {
        return $this->valor->subtrair($this->valor_pago ?? Dinheiro::deUnidadesMenores(0));
    }

    /** As mesmas regras dos CHECK de PostgreSQL (migration), para valerem também em SQLite. */
    private function validarCoerencia(): void
    {
        $valor = $this->valor?->unidadesMenores() ?? 0;
        $pago = $this->valor_pago?->unidadesMenores() ?? 0;

        if ($valor < 0) {
            throw new LogicException('O valor de uma multa não pode ser negativo.');
        }

        if (($this->base_capital?->unidadesMenores() ?? 0) <= 0) {
            throw new LogicException('A base de capital de uma multa tem de ser maior que zero.');
        }

        if ($this->escalao_ordem === null || $this->escalao_ordem < 1) {
            throw new LogicException('O escalão de uma multa tem de ser maior ou igual a 1.');
        }

        if ($this->tipo === null || $this->valor_regra === null || $this->valor_regra <= 0) {
            throw new LogicException('Uma multa tem de guardar o tipo e o valor do escalão aplicado.');
        }

        if ($pago > $valor) {
            throw new LogicException('O valor pago da multa não pode exceder o valor da multa.');
        }
    }
}
