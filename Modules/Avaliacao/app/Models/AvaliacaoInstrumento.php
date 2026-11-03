<?php

namespace Modules\Avaliacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Disciplina\Models\Disciplina;
use Modules\Turma\Models\Turma;

/** Definição de uma avaliação (contínua ou prova trimestral) de uma disciplina numa turma e trimestre; sem notas nem prazo. */
class AvaliacaoInstrumento extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;

    protected $table = 'avaliacao_instrumentos';

    protected $fillable = ['turma_id', 'disciplina_id', 'periodo_id', 'tipo', 'tipo_descricao', 'nome', 'obrigatorio', 'criado_por', 'editado_por'];

    protected $casts = [
        'tipo' => TipoInstrumento::class,
        'obrigatorio' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $instrumento) {
            $instrumento->tipo_descricao = $instrumento->tipo?->label();
        });
    }

    public function turma(): BelongsTo
    {
        return $this->belongsTo(Turma::class);
    }

    public function disciplina(): BelongsTo
    {
        return $this->belongsTo(Disciplina::class);
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(Periodo::class);
    }

    public function notas(): HasMany
    {
        return $this->hasMany(AvaliacaoNota::class, 'instrumento_id');
    }
}
