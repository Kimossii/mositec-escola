<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Financeiro\Casts\DataCast;
use Modules\Financeiro\Casts\DinheiroCast;
use Modules\Financeiro\Enums\EstadoUtilizacaoCredito;
use Modules\Financeiro\Support\AutoriaImutavel;
use Modules\Matricula\Models\Matricula;
use Modules\Usuario\Models\User;

/**
 * Uso de um crédito numa propina (spec Pagamentos §3, §6), independente de qualquer pagamento novo. Só passa
 * de Activa a Anulada (AnularUtilizacaoCreditoAction). Nunca se elimina.
 */
class CreditoUtilizacao extends Model
{
    use PertenceAoTenant;

    public const IMUTAVEIS = ['credito_id', 'propina_id', 'pagamento_id', 'valor', 'data_utilizacao', 'criado_por'];

    public const CAMPOS_DE_ANULACAO = ['motivo_anulacao', 'anulado_por', 'anulado_em'];

    protected $table = 'credito_utilizacoes';

    protected $fillable = ['credito_id', 'propina_id', 'pagamento_id', 'valor', 'data_utilizacao'];

    protected $hidden = ['tenant_id'];

    protected $attributes = [
        'estado' => 1,
    ];

    protected $casts = [
        'credito_id' => 'integer',
        'propina_id' => 'integer',
        'pagamento_id' => 'integer',
        'valor' => DinheiroCast::class,
        'data_utilizacao' => DataCast::class,
        'estado' => EstadoUtilizacaoCredito::class,
        'criado_por' => 'integer',
        'anulado_por' => 'integer',
        'anulado_em' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $utilizacao) {
            $utilizacao->estado_descricao = $utilizacao->estado?->label();
        });

        static::creating(function (self $utilizacao) {
            $utilizacao->criado_por ??= auth()->id();

            if (($utilizacao->valor?->unidadesMenores() ?? 0) <= 0 || $utilizacao->data_utilizacao === null) {
                throw new LogicException('Uma utilização de crédito tem valor maior que zero e data.');
            }

            if ($utilizacao->estado !== EstadoUtilizacaoCredito::ACTIVA) {
                throw new LogicException('Uma utilização de crédito nasce Activa.');
            }

            if ($utilizacao->anulado_em !== null || $utilizacao->anulado_por !== null || $utilizacao->motivo_anulacao !== null) {
                throw new LogicException('Uma utilização Activa não tem dados de anulação.');
            }

            $credito = Credito::query()->find($utilizacao->credito_id);
            $propina = Propina::query()->find($utilizacao->propina_id);
            $matricula = $propina === null ? null : Matricula::query()->withTrashed()->find($propina->matricula_id);

            if ($credito === null || $matricula === null) {
                throw new LogicException('O crédito e a propina têm de existir no tenant da utilização.');
            }

            if ((int) $matricula->aluno_id !== (int) $credito->aluno_id) {
                throw new LogicException('Um crédito só se usa em propinas de matrículas do mesmo aluno.');
            }

            if ($utilizacao->pagamento_id !== null) {
                $pagamento = Pagamento::query()->find($utilizacao->pagamento_id);

                if ($pagamento === null || (int) $pagamento->aluno_id !== (int) $credito->aluno_id || (int) $pagamento->matricula_id !== (int) $propina->matricula_id) {
                    throw new LogicException('A utilização combinada tem de ser da mesma matrícula e do mesmo aluno do pagamento (O8).');
                }
            }
        });

        static::updating(function (self $utilizacao) {
            AutoriaImutavel::verificar($utilizacao);

            $alterados = array_values(array_filter(self::IMUTAVEIS, fn (string $campo) => $utilizacao->isDirty($campo)));

            if ($alterados !== []) {
                throw new LogicException('Uma utilização de crédito nunca se edita (' . implode(', ', $alterados) . '): anule-a.');
            }

            $original = $utilizacao->getOriginal('estado');

            if ($utilizacao->isDirty('estado') && ! ($original === EstadoUtilizacaoCredito::ACTIVA && $utilizacao->estado === EstadoUtilizacaoCredito::ANULADA)) {
                throw new LogicException('Uma utilização de crédito só passa de Activa a Anulada.');
            }

            if (! $utilizacao->isDirty('estado') && $utilizacao->isDirty(self::CAMPOS_DE_ANULACAO)) {
                throw new LogicException('Os dados da anulação só se gravam no acto de anular.');
            }

            $anulada = $utilizacao->estado === EstadoUtilizacaoCredito::ANULADA;

            if ($anulada !== ($utilizacao->anulado_em !== null) || ($anulada && trim((string) $utilizacao->motivo_anulacao) === '')) {
                throw new LogicException('Uma utilização anulada exige o motivo e o instante da anulação.');
            }
        });

        static::deleting(function () {
            throw new LogicException('Uma utilização de crédito nunca é eliminada: anule-a.');
        });
    }

    public function credito(): BelongsTo
    {
        return $this->belongsTo(Credito::class, 'credito_id');
    }

    public function propina(): BelongsTo
    {
        return $this->belongsTo(Propina::class, 'propina_id');
    }

    /** O pagamento do mesmo acto (O8); null na aplicação isolada. */
    public function pagamento(): BelongsTo
    {
        return $this->belongsTo(Pagamento::class, 'pagamento_id');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por');
    }
}
