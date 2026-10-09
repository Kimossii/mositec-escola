<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Enums\Estado;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Core\Traits\SincronizaEstadoDescricao;
use Modules\Financeiro\Casts\DinheiroCast;
use Modules\Financeiro\Enums\Periodicidade;
use Modules\Financeiro\Support\CalendarioDePlano;

/**
 * Configuração de propina de um ano lectivo (NÃO é uma cobrança): periodicidade, valor por
 * período de cobrança e período de aplicação. Alterá-lo só afecta gerações futuras.
 */
class PlanoPropina extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;
    use SincronizaEstadoDescricao;

    protected $table = 'planos_propina';

    protected $fillable = [
        'ano_lectivo_id',
        'nome',
        'descricao',
        'periodicidade',
        'intervalo_meses',
        'valor',
        'mes_inicio',
        'mes_fim',
        'estado',
    ];

    protected $hidden = ['tenant_id', 'criado_por', 'editado_por'];

    protected $attributes = [
        'estado' => 1,
    ];

    protected $casts = [
        'periodicidade' => Periodicidade::class,
        'intervalo_meses' => 'integer',
        'valor' => DinheiroCast::class,
        'mes_inicio' => 'integer',
        'mes_fim' => 'integer',
        'estado' => 'integer',
    ];

    protected static function booted(): void
    {
        // Null-safe: a matriz de isolamento cria models em cru e espera TenantNaoResolvido.
        static::saving(function (self $plano) {
            $plano->periodicidade_descricao = $plano->periodicidade?->label();
        });
    }

    public function anoLectivo(): BelongsTo
    {
        return $this->belongsTo(AnoLectivo::class, 'ano_lectivo_id');
    }

    public function alvos(): HasMany
    {
        return $this->hasMany(PlanoPropinaAlvo::class, 'plano_propina_id');
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('estado', Estado::ATIVO->value);
    }

    public function totalMeses(): int
    {
        return CalendarioDePlano::meses($this->mes_inicio, $this->mes_fim);
    }

    /**
     * @return list<array{ano: int, mes: int}>
     */
    public function competencias(): array
    {
        if ($this->anoLectivo === null) {
            return [];
        }

        return CalendarioDePlano::competencias($this->mes_inicio, $this->mes_fim, $this->anoLectivo->data_inicio);
    }

    /**
     * @return list<array{ordem: int, meses: int, inicio: string, fim: string}>
     */
    public function periodos(): array
    {
        if ($this->anoLectivo === null) {
            return [];
        }

        return CalendarioDePlano::periodos($this->mes_inicio, $this->mes_fim, $this->intervalo_meses, $this->anoLectivo->data_inicio);
    }

    /**
     * Troca os alvos do plano. Cada alvo tem as chaves opcionais nivel_academico_id, curso_id,
     * turno_id e turma_id. Sem alvos, o plano aplica-se a todas as turmas do ano lectivo.
     *
     * @param  list<array<string, ?int>>  $alvos
     */
    public function substituirAlvos(array $alvos): void
    {
        $this->alvos()->delete();

        foreach ($alvos as $alvo) {
            $this->alvos()->create([
                'nivel_academico_id' => $alvo['nivel_academico_id'] ?? null,
                'curso_id' => $alvo['curso_id'] ?? null,
                'turno_id' => $alvo['turno_id'] ?? null,
                'turma_id' => $alvo['turma_id'] ?? null,
            ]);
        }
    }
}
