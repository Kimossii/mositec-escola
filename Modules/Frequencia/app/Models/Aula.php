<?php

namespace Modules\Frequencia\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Horario;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Disciplina\Models\Disciplina;
use Modules\Docente\Models\Docente;
use Modules\Frequencia\Enums\EstadoAula;
use Modules\Frequencia\Enums\TipoAula;
use Modules\Infraestrutura\Models\Sala;
use Modules\Turma\Models\Turma;

/**
 * Uma aula em que se abriu a chamada: fotografia do que aconteceu (data, tempo, turma, disciplina, docente, sala),
 * independente de a grelha mudar depois. No regime «dia» não tem tempo nem disciplina.
 */
class Aula extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;

    protected $table = 'frequencia_aulas';

    protected $fillable = [
        'ano_lectivo_id',
        'turma_id',
        'tipo',
        'tipo_descricao',
        'data',
        'horario_id',
        'disciplina_id',
        'docente_id',
        'sala_id',
        'estado',
        'estado_descricao',
        'motivo_nao_leccionada',
        'criado_por',
        'editado_por',
    ];

    protected $attributes = [
        'estado' => 1, // EstadoAula::REALIZADA
    ];

    protected $casts = [
        'tipo' => TipoAula::class,
        'estado' => EstadoAula::class,
        'data' => 'date',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $aula) {
            $aula->tipo_descricao = $aula->tipo?->label();
            $aula->estado_descricao = $aula->estado?->label();
        });
    }

    public function turma(): BelongsTo
    {
        return $this->belongsTo(Turma::class);
    }

    public function horario(): BelongsTo
    {
        return $this->belongsTo(Horario::class);
    }

    public function disciplina(): BelongsTo
    {
        return $this->belongsTo(Disciplina::class);
    }

    public function docente(): BelongsTo
    {
        return $this->belongsTo(Docente::class);
    }

    public function sala(): BelongsTo
    {
        return $this->belongsTo(Sala::class);
    }

    public function registos(): HasMany
    {
        return $this->hasMany(Registo::class, 'aula_id');
    }
}
