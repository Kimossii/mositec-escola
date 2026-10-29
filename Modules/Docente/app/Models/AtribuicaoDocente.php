<?php

namespace Modules\Docente\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Disciplina\Models\Disciplina;
use Modules\Docente\Enums\PapelDocente;
use Modules\Turma\Models\Turma;

class AtribuicaoDocente extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;
    use SoftDeletes;

    protected $table = 'atribuicoes_docentes';

    protected $fillable = [
        'ano_lectivo_id',
        'turma_id',
        'disciplina_id',
        'docente_id',
        'papel',
        'papel_descricao',
        'data_inicio',
        'data_fim',
        'motivo_encerramento',
        'criado_por',
        'editado_por',
    ];

    protected $casts = [
        'papel' => PapelDocente::class,
        'data_inicio' => 'date',
        'data_fim' => 'date',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $atribuicao) {
            $atribuicao->papel_descricao = $atribuicao->papel?->label();
        });
    }

    public function anoLectivo(): BelongsTo
    {
        return $this->belongsTo(AnoLectivo::class);
    }

    public function turma(): BelongsTo
    {
        return $this->belongsTo(Turma::class);
    }

    public function disciplina(): BelongsTo
    {
        return $this->belongsTo(Disciplina::class);
    }

    /** O docente (pessoa da escola); pode não ter conta de acesso. */
    public function docente(): BelongsTo
    {
        return $this->belongsTo(Docente::class);
    }

    /** Vigente na data: início ≤ data e (fim nulo ou fim ≥ data); extremos inclusivos, ao dia. */
    public function scopeEmVigor(Builder $query, CarbonInterface $data): Builder
    {
        $dia = $data->toDateString();

        return $query->whereDate('data_inicio', '<=', $dia)
            ->where(fn (Builder $q) => $q->whereNull('data_fim')->orWhereDate('data_fim', '>=', $dia));
    }

    /** Estado derivado das datas (não é guardado): 'agendada' | 'em_curso' | 'terminada'. */
    public function estadoNaData(CarbonInterface $data): string
    {
        $dia = $data->toDateString();

        if ($this->data_inicio->toDateString() > $dia) {
            return 'agendada';
        }

        if ($this->data_fim !== null && $this->data_fim->toDateString() < $dia) {
            return 'terminada';
        }

        return 'em_curso';
    }
}
