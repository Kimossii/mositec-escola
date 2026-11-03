<?php

namespace Modules\Avaliacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;

/** Prolongamento de um prazo para um caso concreto: uma turma, uma disciplina e/ou um docente (nulos = todos). */
class AvaliacaoPrazoExcepcao extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;

    protected $table = 'avaliacao_prazo_excepcoes';

    protected $fillable = ['prazo_id', 'turma_id', 'disciplina_id', 'docente_id', 'data_limite', 'motivo', 'criado_por', 'editado_por'];

    protected $casts = ['data_limite' => 'date'];

    public function prazo(): BelongsTo
    {
        return $this->belongsTo(AvaliacaoPrazo::class, 'prazo_id');
    }
}
