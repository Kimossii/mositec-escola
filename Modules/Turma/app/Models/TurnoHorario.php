<?php

namespace Modules\Turma\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Horario;

class TurnoHorario extends Model
{
    protected $table = 'turno_horarios';

    protected $fillable = [
        'turno_id',
        'horario_id',
        'ordem',
    ];

    protected $casts = [
        'turno_id' => 'integer',
        'horario_id' => 'integer',
        'ordem' => 'integer',
    ];

    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class);
    }

    public function horario(): BelongsTo
    {
        return $this->belongsTo(Horario::class);
    }
}
