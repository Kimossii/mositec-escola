<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Financeiro\Casts\DinheiroCast;
use Modules\Financeiro\Enums\EstadoAcordo;
use Modules\Matricula\Models\Matricula;

/**
 * Acordo de pagamento em prestações de uma matrícula. Enquanto activo: as propinas incluídas aceitam pagamento parcial
 * e as suas multas ficam congeladas. O que já se pagou e o que falta calcula-se pelas propinas (ProgressoDoAcordo).
 */
class Acordo extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;

    protected $table = 'acordos_divida';

    protected $fillable = ['matricula_id', 'aluno_id', 'moeda', 'divida_inicial', 'desconto_multa_pct', 'desconto', 'total', 'estado', 'motivo', 'motivo_encerramento'];

    protected $hidden = ['tenant_id', 'criado_por', 'editado_por'];

    protected $attributes = ['estado' => 1, 'desconto_multa_pct' => 0];

    protected $casts = [
        'divida_inicial' => DinheiroCast::class,
        'desconto' => DinheiroCast::class,
        'total' => DinheiroCast::class,
        'desconto_multa_pct' => 'integer',
        'estado' => EstadoAcordo::class,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $acordo) {
            $acordo->estado_descricao = $acordo->estado?->label();
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

    public function propinas(): HasMany
    {
        return $this->hasMany(AcordoPropina::class);
    }

    public function prestacoes(): HasMany
    {
        return $this->hasMany(AcordoPrestacao::class)->orderBy('ordem');
    }
}
