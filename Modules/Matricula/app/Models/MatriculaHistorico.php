<?php

namespace Modules\Matricula\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Usuario\Models\User;

class MatriculaHistorico extends Model
{
    public $timestamps = false;

    protected $table = 'matricula_historicos';

    protected $fillable = [
        'matricula_id',
        'estado_anterior',
        'estado_novo',
        'utilizador_id',
    ];

    protected $casts = [
        'estado_anterior' => EstadoMatriculaEnum::class,
        'estado_novo' => EstadoMatriculaEnum::class,
        'created_at' => 'datetime',
    ];

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(Matricula::class);
    }

    public function utilizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilizador_id');
    }
}
