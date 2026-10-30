<?php

namespace Modules\Turma\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Core\Traits\SincronizaEstadoDescricao;
use Modules\Curso\Models\Curso;
use Modules\Usuario\Models\User;

class Turma extends Model
{
    use PertenceAoTenant;
    use SoftDeletes;
    use RegistaAutoria;
    use SincronizaEstadoDescricao;

    protected $table = 'turmas';

    protected $fillable = [
        'ano_lectivo_id',
        'nivel_academico_id',
        'curso_id',
        'codigo',
        'nome',
        'turno_id',
        'estado',
        'estado_descricao',
        'criado_por',
        'editado_por',
    ];

    protected $casts = [
        'estado' => 'integer',
    ];

    public function anoLectivo(): BelongsTo
    {
        return $this->belongsTo(AnoLectivo::class);
    }
    public function nivelAcademico(): BelongsTo
    {
        return $this->belongsTo(NivelAcademico::class);
    }

    public function curso(): BelongsTo
    {
        return $this->belongsTo(Curso::class);
    }

    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class);
    }

    public function turmaSalas(): HasMany
    {
        return $this->hasMany(TurmaSala::class);
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function editadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editado_por');
    }

    /**
     * Rótulo único da turma. Curto: «Turma 10.ª A». Completo: «Turma 10.ª A · Manhã · 2026/2027».
     *
     * Só usa o turno e o ano lectivo se a relação já estiver carregada (nunca dispara queries),
     * por isso quem pede o formato completo deve fazer eager load de `turno` e `anoLectivo`.
     */
    public function rotulo(bool $completo = false): string
    {
        $atributos = $this->getAttributes();
        $nome = trim((string) ($atributos['nome'] ?? ''));

        if ($nome === '') {
            $nome = trim((string) ($atributos['codigo'] ?? ''));
        }

        $partes = [preg_match('/^turma\b/iu', $nome) === 1 ? $nome : trim("Turma {$nome}")];

        if ($completo) {
            foreach (['turno', 'anoLectivo'] as $relacao) {
                $parte = $this->relationLoaded($relacao) ? trim((string) $this->getRelation($relacao)?->nome) : '';

                // «6.ª Classe - Manhã» já diz o turno: repeti-lo daria «… - Manhã · Manhã».
                if ($parte !== '' && mb_stripos($nome, $parte) === false) {
                    $partes[] = $parte;
                }
            }
        }

        return implode(' · ', $partes);
    }
}
