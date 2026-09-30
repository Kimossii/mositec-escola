<?php

namespace Modules\Turma\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Infraestrutura\Models\Sala;

class TurmaSala extends Model
{
    protected $table = 'turma_salas';

    protected $fillable = [
        'turma_id',
        'sala_id',
        'inicio',
        'fim',
    ];

    protected $casts = [
        'turma_id' => 'integer',
        'sala_id' => 'integer',
        'inicio' => 'date',
        'fim' => 'date',
    ];

    public function turma(): BelongsTo
    {
        return $this->belongsTo(Turma::class);
    }

    public function sala(): BelongsTo
    {
        return $this->belongsTo(Sala::class);
    }
}
