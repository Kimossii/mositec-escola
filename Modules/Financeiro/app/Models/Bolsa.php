<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Aluno\Models\Aluno;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Financeiro\Enums\EstadoBolsa;
use Modules\Financeiro\Support\InvalidaTotaisFinanceiros;

/**
 * Bolsa de estudo: cobre uma percentagem (pontos-base, 10000 = 100%) das propinas de um aluno num ano lectivo,
 * entre `data_inicio` e `data_fim`. Afecta só as propinas geradas depois (ou aplicadas à mão às em aberto);
 * terminá-la não mexe nas propinas que já nasceram com desconto.
 */
class Bolsa extends Model
{
    use InvalidaTotaisFinanceiros;
    use PertenceAoTenant;
    use RegistaAutoria;

    protected $table = 'bolsas';

    protected $fillable = ['aluno_id', 'ano_lectivo_id', 'nome', 'percentagem', 'data_inicio', 'data_fim', 'motivo', 'estado', 'motivo_termino'];

    protected $hidden = ['tenant_id', 'criado_por', 'editado_por'];

    protected $attributes = ['estado' => 1];

    protected $casts = [
        'percentagem' => 'integer',
        'data_inicio' => 'date',
        'data_fim' => 'date',
        'estado' => EstadoBolsa::class,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $bolsa) {
            $bolsa->estado_descricao = $bolsa->estado?->label();
        });
    }

    public function aluno(): BelongsTo
    {
        return $this->belongsTo(Aluno::class);
    }

    public function anoLectivo(): BelongsTo
    {
        return $this->belongsTo(AnoLectivo::class);
    }

    /** Quanto a bolsa abate a um valor (arredonda ao mais próximo), em unidades menores. */
    public function descontoSobre(int $valor): int
    {
        return intdiv($valor * $this->percentagem + 5000, 10000);
    }
}
