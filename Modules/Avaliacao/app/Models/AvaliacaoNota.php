<?php

namespace Modules\Avaliacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Avaliacao\Enums\EstadoNota;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Matricula\Models\InscricaoDisciplina;

/** Valor de um aluno (inscrição na disciplina) num instrumento. `valor` é nulo quando a nota está anulada. */
class AvaliacaoNota extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;

    protected $table = 'avaliacao_notas';

    protected $fillable = ['instrumento_id', 'inscricao_disciplina_id', 'valor', 'estado', 'estado_descricao', 'criado_por', 'editado_por'];

    protected $casts = [
        'valor' => 'decimal:2',
        'estado' => EstadoNota::class,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $nota) {
            $nota->estado_descricao = $nota->estado?->label();
        });
    }

    public function instrumento(): BelongsTo
    {
        return $this->belongsTo(AvaliacaoInstrumento::class, 'instrumento_id');
    }

    public function inscricaoDisciplina(): BelongsTo
    {
        return $this->belongsTo(InscricaoDisciplina::class);
    }

    public function historico(): HasMany
    {
        return $this->hasMany(AvaliacaoNotaHistorico::class, 'nota_id');
    }
}
