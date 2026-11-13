<?php

namespace Modules\Turma\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Aluno\Models\AlunoEnquadramentoAcademico;
use Modules\Financeiro\Models\CatalogoAlvo;
use Modules\Financeiro\Models\PlanoPropinaAlvo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Core\Traits\SincronizaEstadoDescricao;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\Usuario\Models\User;

class NivelAcademico extends Model
{
    use PertenceAoTenant;
    use SoftDeletes;
    use RegistaAutoria;
    use SincronizaEstadoDescricao;

    protected $table = 'niveis_academicos';

    protected $fillable = [
        'estabelecimento_id',
        'codigo',
        'nome',
        'etapa_ensino',
        'ordem',
        'estado',
        'estado_descricao',
        'criado_por',
        'editado_por',
    ];

    protected $casts = [
        'estado' => 'integer',
        'ordem' => 'integer',
        'etapa_ensino' => EtapaEnsinoEnum::class,
    ];

    public function estabelecimento(): BelongsTo
    {
        return $this->belongsTo(Estabelecimento::class, 'estabelecimento_id');
    }

    public function turmas(): HasMany
    {
        return $this->hasMany(Turma::class);
    }

    /**
     * A classe já tem informação ligada (turmas, planos curriculares, alunos enquadrados, planos de propina ou catálogo)?
     * Só então não se pode retirar sozinha. As ligações às regras de avaliação são configuração, não contam: saem com a classe.
     */
    public function temVinculos(): bool
    {
        if ($this->turmas()->exists() || $this->planosCurriculares()->exists()) {
            return true;
        }

        // Pelos models (isolados por tenant), nunca por consultas directas às tabelas.
        foreach ([AlunoEnquadramentoAcademico::class, PlanoPropinaAlvo::class, CatalogoAlvo::class] as $modelo) {
            if ($modelo::query()->where('nivel_academico_id', $this->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    public function planosCurriculares(): HasMany
    {
        return $this->hasMany(PlanoCurricular::class);
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function editadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editado_por');
    }

    protected static function booted(): void
    {
        static::saving(function (NivelAcademico $nivelAcademico) {
            if ($nivelAcademico->etapa_ensino !== null) {
                $nivelAcademico->etapa_ensino_descricao = $nivelAcademico->etapa_ensino->label();
            }
        });
    }
}
