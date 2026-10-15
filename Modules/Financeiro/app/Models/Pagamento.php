<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Financeiro\Casts\DataCast;
use Modules\Financeiro\Casts\DinheiroCast;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Support\AutoriaImutavel;
use Modules\Financeiro\Support\Moeda;
use Modules\Matricula\Models\Matricula;
use Modules\Usuario\Models\User;

/**
 * Dinheiro efectivamente recebido de uma matrícula (spec Pagamentos §3). Nasce Confirmado pela
 * RegistarPagamentoAction; a única alteração permitida é anular, uma vez, com motivo (AnularPagamentoAction).
 * Corrigir um pagamento = anular + registar outro. Nunca se elimina. Em PostgreSQL um trigger repete estas regras.
 */
class Pagamento extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;

    /** Dados financeiros e de identificação: nunca mudam depois de registados. */
    public const IMUTAVEIS = [
        'matricula_id', 'aluno_id', 'metodo_pagamento_id', 'numero', 'valor', 'moeda', 'cambio_usd',
        'data_pagamento', 'referencia', 'observacao',
    ];

    public const CAMPOS_DE_ANULACAO = ['motivo_anulacao', 'anulado_por', 'anulado_em'];

    protected $table = 'pagamentos';

    protected $fillable = [
        'matricula_id',
        'metodo_pagamento_id',
        'valor',
        'data_pagamento',
        'referencia',
        'observacao',
    ];

    protected $hidden = ['tenant_id', 'criado_por', 'editado_por'];

    protected $attributes = [
        'estado' => 1,
    ];

    protected $casts = [
        'matricula_id' => 'integer',
        'aluno_id' => 'integer',
        'metodo_pagamento_id' => 'integer',
        'valor' => DinheiroCast::class,
        'cambio_usd' => 'integer',
        'data_pagamento' => DataCast::class,
        'estado' => EstadoPagamento::class,
        'anulado_por' => 'integer',
        'anulado_em' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Null-safe: a matriz de isolamento grava models vazios sem contexto e espera TenantNaoResolvido.
        static::saving(function (self $pagamento) {
            $pagamento->estado_descricao = $pagamento->estado?->label();
        });

        // Corre depois da verificação de tenant da trait.
        static::creating(function (self $pagamento) {
            $pagamento->validarCriacao();
        });

        static::updating(function (self $pagamento) {
            AutoriaImutavel::verificar($pagamento);

            $alterados = array_values(array_filter(self::IMUTAVEIS, fn (string $campo) => $pagamento->isDirty($campo)));

            if ($alterados !== []) {
                throw new LogicException('Um pagamento registado nunca se edita (' . implode(', ', $alterados) . '): anule-o e registe outro.');
            }

            $original = $pagamento->getOriginal('estado');

            if ($pagamento->isDirty('estado') && ! ($original === EstadoPagamento::CONFIRMADO && $pagamento->estado === EstadoPagamento::ANULADO)) {
                throw new LogicException('Um pagamento só passa de Confirmado a Anulado, e uma única vez.');
            }

            if (! $pagamento->isDirty('estado') && $pagamento->isDirty(self::CAMPOS_DE_ANULACAO)) {
                throw new LogicException('Os dados da anulação só se gravam no acto de anular.');
            }

            $pagamento->validarCoerencia();
        });

        static::deleting(function () {
            throw new LogicException('Um pagamento nunca é eliminado: anule-o.');
        });
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(Matricula::class, 'matricula_id')->withTrashed();
    }

    public function aluno(): BelongsTo
    {
        return $this->belongsTo(Aluno::class, 'aluno_id');
    }

    public function metodo(): BelongsTo
    {
        return $this->belongsTo(MetodoPagamento::class, 'metodo_pagamento_id');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por');
    }

    public function distribuicoes(): HasMany
    {
        return $this->hasMany(PagamentoPropina::class, 'pagamento_id')->orderBy('id');
    }

    /** O crédito gerado pelo excedente (no máximo um). */
    public function credito(): HasOne
    {
        return $this->hasOne(Credito::class, 'origem_pagamento_id');
    }

    /** Utilizações de crédito combinadas no mesmo acto (O8). Independentes do pagamento: anulá-lo não as anula. */
    public function utilizacoesCombinadas(): HasMany
    {
        return $this->hasMany(CreditoUtilizacao::class, 'pagamento_id')->orderBy('id');
    }

    public function estaConfirmado(): bool
    {
        return $this->estado === EstadoPagamento::CONFIRMADO;
    }

    private function validarCriacao(): void
    {
        if ($this->estado !== EstadoPagamento::CONFIRMADO) {
            throw new LogicException('Um pagamento regista-se sempre Confirmado.');
        }

        if ($this->motivo_anulacao !== null) {
            throw new LogicException('Um pagamento Confirmado não tem motivo de anulação.');
        }

        if (trim((string) $this->numero) === '') {
            throw new LogicException('O número do pagamento é obrigatório.');
        }

        if ($this->data_pagamento === null) {
            throw new LogicException('A data do pagamento é obrigatória.');
        }

        if ($this->moeda === null || ! Moeda::existe($this->moeda)) {
            throw new LogicException('Moeda desconhecida no pagamento.');
        }

        if ($this->cambio_usd !== null && $this->cambio_usd <= 0) {
            throw new LogicException('O câmbio do pagamento tem de ser maior que zero (ou nulo).');
        }

        if ($this->referencia !== null && trim($this->referencia) === '') {
            throw new LogicException('Uma referência vazia grava-se como nula.');
        }

        // O scope do tenant esconde a matrícula e o método de outra escola: ambos têm de existir aqui.
        $matricula = Matricula::query()->withTrashed()->find($this->matricula_id);

        if ($matricula === null || ! MetodoPagamento::query()->whereKey($this->metodo_pagamento_id)->exists()) {
            throw new LogicException('A matrícula e o método de pagamento têm de existir no tenant do pagamento.');
        }

        if ((int) $matricula->aluno_id !== (int) $this->aluno_id) {
            throw new LogicException('O aluno do pagamento tem de ser o aluno da matrícula.');
        }

        $this->validarCoerencia();
    }

    /** As mesmas regras dos CHECK de PostgreSQL (migration), para valerem também em SQLite. */
    private function validarCoerencia(): void
    {
        if (($this->valor?->unidadesMenores() ?? 0) <= 0) {
            throw new LogicException('O valor do pagamento tem de ser maior que zero.');
        }

        $anulado = $this->estado === EstadoPagamento::ANULADO;

        if ($anulado !== ($this->anulado_em !== null)) {
            throw new LogicException('Um pagamento anulado exige o instante da anulação, e só ele o tem.');
        }

        if ($anulado && trim((string) $this->motivo_anulacao) === '') {
            throw new LogicException('Um pagamento anulado exige o motivo da anulação.');
        }

        if (! $anulado && $this->anulado_por !== null) {
            throw new LogicException('Só um pagamento anulado tem o autor da anulação.');
        }
    }
}
