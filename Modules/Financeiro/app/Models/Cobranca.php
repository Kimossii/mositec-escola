<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Financeiro\Casts\DinheiroCast;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\OrigemCobranca;
use Modules\Matricula\Models\Matricula;

/**
 * Cobrança de produtos e serviços a uma matrícula. `valor_pago` e `estado` só os escreve RecalcularCobranca
 * (a partir dos pagamentos Confirmados); a criação e o cancelamento são das suas Actions.
 */
class Cobranca extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;

    protected $table = 'cobrancas';

    protected $fillable = ['matricula_id', 'aluno_id', 'moeda', 'total', 'origem', 'data_limite', 'observacao'];

    protected $hidden = ['tenant_id', 'criado_por', 'editado_por'];

    protected $attributes = ['estado' => 1, 'valor_pago' => 0, 'origem' => 1];

    protected $casts = [
        'total' => DinheiroCast::class,
        'valor_pago' => DinheiroCast::class,
        'estado' => EstadoCobranca::class,
        'origem' => OrigemCobranca::class,
        'data_limite' => 'date',
        'cancelamento_data' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $cobranca) {
            $cobranca->estado_descricao = $cobranca->estado?->label();
            $cobranca->origem_descricao = $cobranca->origem?->label();
        });
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(Matricula::class);
    }

    public function aluno(): BelongsTo
    {
        return $this->belongsTo(Aluno::class);
    }

    public function itens(): HasMany
    {
        return $this->hasMany(CobrancaItem::class)->orderBy('id');
    }

    public function pagamentos(): HasMany
    {
        return $this->hasMany(PagamentoCobranca::class);
    }

    public function saldo(): int
    {
        return $this->total->unidadesMenores() - $this->valor_pago->unidadesMenores();
    }
}
