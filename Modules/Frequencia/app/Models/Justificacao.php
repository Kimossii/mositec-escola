<?php

namespace Modules\Frequencia\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Usuario\Models\User;

/** A justificação de uma falta (no máximo uma por registo). Remover-se deixa história em `RegistoHistorico`. */
class Justificacao extends Model
{
    use PertenceAoTenant;

    protected $table = 'frequencia_justificacoes';

    protected $fillable = ['registo_id', 'motivo', 'documento_referencia', 'justificada_por', 'justificada_em'];

    protected $casts = ['justificada_em' => 'datetime'];

    public function registo(): BelongsTo
    {
        return $this->belongsTo(Registo::class, 'registo_id');
    }

    public function justificadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'justificada_por');
    }
}
