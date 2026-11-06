<?php

namespace Modules\Avaliacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Disciplina\Models\Disciplina;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Avaliacao\Enums\ModoArredondamento;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Turma\Models\NivelAcademico;

class AvaliacaoRegra extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;

    protected $table = 'avaliacao_regras';

    protected $fillable = [
        'ano_lectivo_id',
        'nivel_academico_id',
        'escala_maxima',
        'nota_minima',
        'peso_mac',
        'peso_pt',
        'peso_mt1',
        'peso_mt2',
        'peso_mt3',
        'peso_mfd_exame',
        'peso_exame',
        'recurso_max_disciplinas',
        'casas_decimais',
        'modo_arredondamento',
        'modo_arredondamento_descricao',
        'reprova_por_aproveitamento',
        'criado_por',
        'editado_por',
    ];

    protected $casts = [
        'escala_maxima' => 'integer',
        'nota_minima' => 'decimal:2',
        'peso_mac' => 'decimal:2',
        'peso_pt' => 'decimal:2',
        'peso_mt1' => 'decimal:2',
        'peso_mt2' => 'decimal:2',
        'peso_mt3' => 'decimal:2',
        'peso_mfd_exame' => 'decimal:2',
        'peso_exame' => 'decimal:2',
        'recurso_max_disciplinas' => 'integer',
        'casas_decimais' => 'integer',
        'modo_arredondamento' => ModoArredondamento::class,
        'reprova_por_aproveitamento' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $regra) {
            $regra->modo_arredondamento_descricao = $regra->modo_arredondamento?->label();
        });
    }

    public function anoLectivo(): BelongsTo
    {
        return $this->belongsTo(AnoLectivo::class);
    }

    public function nivelAcademico(): BelongsTo
    {
        return $this->belongsTo(NivelAcademico::class);
    }

    /** Disciplinas-chave: uma negativa a qualquer uma delas tira o direito ao recurso (reprovação directa). */
    public function disciplinasChave(): BelongsToMany
    {
        return $this->belongsToMany(Disciplina::class, 'avaliacao_regra_disciplinas_chave', 'regra_id', 'disciplina_id')->withTimestamps();
    }

    /** Pesos dos três trimestres em percentagem, ou `null` quando pesam o mesmo. */
    public function pesosDosTrimestres(): ?array
    {
        if ($this->peso_mt1 === null || $this->peso_mt2 === null || $this->peso_mt3 === null) {
            return null;
        }

        return [(string) $this->peso_mt1, (string) $this->peso_mt2, (string) $this->peso_mt3];
    }
}
