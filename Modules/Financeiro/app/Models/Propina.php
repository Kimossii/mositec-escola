<?php

namespace Modules\Financeiro\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Financeiro\Casts\DataCast;
use Modules\Financeiro\Casts\DinheiroCast;
use Modules\Financeiro\Contracts\CobrancaResolvivel;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\Moeda;
use Modules\Financeiro\Support\TaxaCambio;
use Modules\Matricula\Models\Matricula;
use Modules\Usuario\Models\User;

/**
 * Obrigação financeira concreta de uma matrícula num período (spec Propinas §3). Guarda um snapshot do
 * plano, das regras e da moeda/câmbio no momento da geração; alterações posteriores nunca a afectam.
 * `valor_pago`, `estado` (entre Em Aberto, Parcialmente Paga e Paga) e `capital_liquidado_em` só são
 * escritos por RecalcularPropina. Nunca se elimina: cancela-se ou anula-se.
 */
class Propina extends Model implements CobrancaResolvivel
{
    use PertenceAoTenant;
    use RegistaAutoria;

    /** Estados persistidos de uma cobrança activa (índices únicos parciais e sobreposição). */
    public const ESTADOS_ACTIVOS = [1, 2, 3];

    /** Snapshot da geração: nunca muda depois de criada. Plano, ordem e valor só mudam pela alteração de preço (F4). */
    public const IMUTAVEIS = [
        'matricula_id', 'ano_lectivo_id', 'periodo_inicio', 'periodo_fim', 'valor_original', 'moeda', 'cambio_usd',
        'data_vencimento', 'dias_tolerancia', 'data_limite', 'origem_geracao', 'motivo_geracao',
    ];

    protected $table = 'propinas';

    protected $fillable = [
        'matricula_id',
        'plano_propina_id',
        'ano_lectivo_id',
        'ordem',
        'periodo_inicio',
        'periodo_fim',
        'valor',
        'moeda',
        'cambio_usd',
        'data_vencimento',
        'dias_tolerancia',
        'origem_geracao',
        'motivo_geracao',
    ];

    protected $hidden = ['tenant_id', 'criado_por', 'editado_por'];

    protected $attributes = [
        'estado' => 1,
        'valor_pago' => 0,
    ];

    protected $casts = [
        'matricula_id' => 'integer',
        'plano_propina_id' => 'integer',
        'ano_lectivo_id' => 'integer',
        'ordem' => 'integer',
        'periodo_inicio' => DataCast::class,
        'periodo_fim' => DataCast::class,
        'valor_original' => DinheiroCast::class,
        'valor' => DinheiroCast::class,
        'valor_pago' => DinheiroCast::class,
        'cambio_usd' => 'integer',
        'data_vencimento' => DataCast::class,
        'dias_tolerancia' => 'integer',
        'data_limite' => DataCast::class,
        'capital_liquidado_em' => DataCast::class,
        'estado' => EstadoCobranca::class,
        'origem_geracao' => OrigemGeracaoPropina::class,
        'cancelado_em' => 'datetime',
        'anulado_em' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Null-safe: a matriz de isolamento grava models vazios em cru e espera TenantNaoResolvido, que a
        // trait lança no `creating` (depois deste `saving`).
        static::saving(function (self $propina) {
            if ($propina->estado !== null && ! $propina->estado->persistido()) {
                throw new LogicException('Pendente e Em Atraso são estados derivados e nunca se gravam.');
            }

            $propina->estado_descricao = $propina->estado?->label();
            $propina->origem_geracao_descricao = $propina->origem_geracao?->label();
        });

        // Corre depois da verificação de tenant da trait (registada antes, ao arrancar a trait).
        static::creating(function (self $propina) {
            $propina->valor_original = $propina->valor;

            if ($propina->data_vencimento !== null && $propina->dias_tolerancia !== null) {
                $propina->data_limite = $propina->data_vencimento->addDays($propina->dias_tolerancia);
            }

            $propina->validarCriacao();
        });

        static::updating(function (self $propina) {
            $alterados = array_values(array_filter(self::IMUTAVEIS, fn (string $campo) => $propina->isDirty($campo)));

            if ($alterados !== []) {
                throw new LogicException('Campos imutáveis de uma propina não podem mudar: ' . implode(', ', $alterados) . '.');
            }

            $original = $propina->getOriginal('estado');

            if ($original instanceof EstadoCobranca && $propina->isDirty('estado') && ! $original->podeTransitarPara($propina->estado)) {
                throw new LogicException(
                    $original->transicoesPermitidas() === []
                        ? "Uma propina {$original->label()} é terminal e não pode mudar de estado."
                        : "Transição inválida de {$original->label()} para {$propina->estado?->label()}.",
                );
            }

            // RegistaAutoria põe editado_por = auth()->id(), o que apaga a autoria em jobs e comandos
            // (sem utilizador autenticado): aí mantém-se o último editor conhecido.
            if (auth()->id() === null && $propina->getOriginal('editado_por') !== null) {
                $propina->editado_por = $propina->getOriginal('editado_por');
            }

            if ($propina->isDirty('plano_propina_id')) {
                $plano = PlanoPropina::query()->find($propina->plano_propina_id);

                if ($plano === null || (int) $plano->ano_lectivo_id !== (int) $propina->ano_lectivo_id) {
                    throw new LogicException('O plano de uma propina só pode mudar para um plano do mesmo ano lectivo.');
                }
            }

            $propina->validarCoerencia();
        });

        static::deleting(function () {
            throw new LogicException('Uma propina nunca é eliminada: cancele-a ou anule-a.');
        });
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(Matricula::class, 'matricula_id')->withTrashed();
    }

    public function plano(): BelongsTo
    {
        return $this->belongsTo(PlanoPropina::class, 'plano_propina_id');
    }

    public function anoLectivo(): BelongsTo
    {
        return $this->belongsTo(AnoLectivo::class, 'ano_lectivo_id')->withTrashed();
    }

    public function canceladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelado_por');
    }

    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por');
    }

    public function scopeActivas(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('estado'), self::ESTADOS_ACTIVOS);
    }

    /**
     * Tradução SQL de EstadoCobranca::resolver() para filtros e listas (spec §4: "os filtros por estado
     * resolvido traduzem-se em queries por data"). Só compara colunas com o dia de hoje: graças a
     * `data_limite` (snapshot) não há aritmética de datas nem SQL específico de um motor. Depende de o
     * estado persistido ser coerente com o valor pago (RecalcularPropina, model e CHECK em pgsql).
     */
    public function scopeComEstadoResolvido(Builder $query, EstadoCobranca $estado, CarbonInterface $hoje): Builder
    {
        $dia = $hoje->toDateString();
        $colunaEstado = $this->qualifyColumn('estado');
        $limite = $this->qualifyColumn('data_limite');
        $inicio = $this->qualifyColumn('periodo_inicio');
        $aberto = EstadoCobranca::EM_ABERTO->value;
        $parcial = EstadoCobranca::PARCIALMENTE_PAGA->value;

        return match ($estado) {
            EstadoCobranca::CANCELADA, EstadoCobranca::ANULADA, EstadoCobranca::PAGA => $query->where($colunaEstado, $estado->value),
            EstadoCobranca::EM_ATRASO => $query->whereIn($colunaEstado, [$aberto, $parcial])->where($limite, '<', $dia),
            EstadoCobranca::PARCIALMENTE_PAGA => $query->where($colunaEstado, $parcial)->where($limite, '>=', $dia),
            EstadoCobranca::PENDENTE => $query->where($colunaEstado, $aberto)->where($inicio, '>', $dia)->where($limite, '>=', $dia),
            EstadoCobranca::EM_ABERTO => $query->where($colunaEstado, $aberto)->where($inicio, '<=', $dia)->where($limite, '>=', $dia),
        };
    }

    public function saldo(): Dinheiro
    {
        return $this->valorDevido()->subtrair($this->valorRecebido());
    }

    public function estadoResolvido(CarbonInterface $hoje): EstadoCobranca
    {
        return EstadoCobranca::resolver($this, $hoje);
    }

    public function taxaCambio(): ?TaxaCambio
    {
        return $this->cambio_usd === null ? null : TaxaCambio::deMicros($this->cambio_usd);
    }

    public function estadoPersistido(): EstadoCobranca
    {
        return $this->estado;
    }

    public function valorDevido(): Dinheiro
    {
        return $this->valor;
    }

    public function valorRecebido(): Dinheiro
    {
        return $this->valor_pago ?? Dinheiro::deUnidadesMenores(0);
    }

    public function inicioDoPeriodo(): CarbonInterface
    {
        return $this->periodo_inicio;
    }

    public function limiteSemAtraso(): CarbonInterface
    {
        return $this->data_limite;
    }

    private function validarCriacao(): void
    {
        if ($this->origem_geracao === null) {
            throw new LogicException('A origem da geração da propina é obrigatória.');
        }

        if ($this->origem_geracao->exigeMotivo() && trim((string) $this->motivo_geracao) === '') {
            throw new LogicException('Uma propina retroactiva exige o motivo da geração.');
        }

        if ($this->ordem === null || $this->ordem < 1) {
            throw new LogicException('A ordem do período tem de ser maior ou igual a 1.');
        }

        if ($this->periodo_inicio === null || $this->periodo_fim === null || $this->periodo_fim->lt($this->periodo_inicio)) {
            throw new LogicException('O período da propina é inválido: o fim não pode ser anterior ao início.');
        }

        if ($this->data_vencimento === null || $this->data_vencimento->lt($this->periodo_inicio)) {
            throw new LogicException('O vencimento não pode ser anterior ao início do período.');
        }

        if ($this->dias_tolerancia === null || $this->dias_tolerancia < 0) {
            throw new LogicException('A tolerância (dias) não pode ser negativa.');
        }

        if ($this->moeda === null || ! Moeda::existe($this->moeda)) {
            throw new LogicException('Moeda desconhecida na propina.');
        }

        if ($this->cambio_usd !== null && $this->cambio_usd <= 0) {
            throw new LogicException('O câmbio da propina tem de ser maior que zero (ou nulo).');
        }

        $this->validarCoerencia();

        // O scope do tenant esconde matrícula e plano de outra escola: ambos têm de existir aqui.
        $matricula = Matricula::query()->find($this->matricula_id);
        $plano = PlanoPropina::query()->find($this->plano_propina_id);

        if ($matricula === null || $plano === null) {
            throw new LogicException('A matrícula e o plano têm de existir no tenant da propina.');
        }

        if ((int) $plano->ano_lectivo_id !== (int) $matricula->ano_lectivo_id || (int) $this->ano_lectivo_id !== (int) $plano->ano_lectivo_id) {
            throw new LogicException('A propina, a matrícula e o plano têm de ser do mesmo ano lectivo.');
        }
    }

    /**
     * As mesmas regras dos CHECK de PostgreSQL (migration), para valerem também em SQLite.
     */
    private function validarCoerencia(): void
    {
        $valor = $this->valor?->unidadesMenores() ?? 0;
        $pago = $this->valor_pago?->unidadesMenores() ?? 0;

        if ($valor <= 0) {
            throw new LogicException('O valor da propina tem de ser maior que zero.');
        }

        if ($pago > $valor) {
            throw new LogicException('O valor pago não pode exceder o valor da propina.');
        }

        $coerente = match ($this->estado) {
            EstadoCobranca::EM_ABERTO => $pago === 0,
            EstadoCobranca::PARCIALMENTE_PAGA => $pago > 0 && $pago < $valor,
            EstadoCobranca::PAGA => $pago === $valor,
            EstadoCobranca::CANCELADA, EstadoCobranca::ANULADA => $pago === 0,
            default => false,
        };

        if (! $coerente) {
            throw new LogicException("O estado {$this->estado?->label()} não é coerente com o valor pago.");
        }

        if (($this->capital_liquidado_em !== null) !== ($pago === $valor)) {
            throw new LogicException('A data de liquidação do capital só existe quando a propina está totalmente paga.');
        }

        if (($this->estado === EstadoCobranca::CANCELADA) !== ($this->cancelado_em !== null)) {
            throw new LogicException('Uma propina cancelada exige a data de cancelamento, e só ela a tem.');
        }

        if (($this->estado === EstadoCobranca::ANULADA) !== ($this->anulado_em !== null)) {
            throw new LogicException('Uma propina anulada exige a data de anulação, e só ela a tem.');
        }

        if ($this->estado === EstadoCobranca::CANCELADA && trim((string) $this->motivo_cancelamento) === '') {
            throw new LogicException('Uma propina cancelada exige o motivo do cancelamento.');
        }

        if ($this->estado !== EstadoCobranca::CANCELADA && $this->cancelado_por !== null) {
            throw new LogicException('Só uma propina cancelada tem o autor do cancelamento.');
        }

        if ($this->estado === EstadoCobranca::ANULADA && trim((string) $this->motivo_anulacao) === '') {
            throw new LogicException('Uma propina anulada exige o motivo da anulação.');
        }

        if ($this->estado !== EstadoCobranca::ANULADA && $this->anulado_por !== null) {
            throw new LogicException('Só uma propina anulada tem o autor da anulação.');
        }

        if ($this->dias_tolerancia !== null && $this->dias_tolerancia < 0) {
            throw new LogicException('A tolerância (dias) não pode ser negativa.');
        }

        if ($this->data_vencimento !== null && $this->dias_tolerancia !== null && $this->data_limite !== null
            && $this->data_vencimento->addDays($this->dias_tolerancia)->toDateString() !== $this->data_limite->toDateString()) {
            throw new LogicException('O limite da propina tem de ser o vencimento mais a tolerância.');
        }
    }
}
