<?php

namespace Modules\Turma\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Usuario\Models\User;

class Turno extends Model
{
    protected $table = 'turnos';

    protected $fillable = [
        'nome',
        'descricao',
        'estado',
        'estado_descricao',
        'criado_por',
        'editado_por',
    ];

    protected $casts = [
        'estado' => 'integer',
    ];

    public function turmas(): HasMany
    {
        return $this->hasMany(Turma::class);
    }

    public function turnoHorarios(): HasMany
    {
        return $this->hasMany(TurnoHorario::class);
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function editadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editado_por');
    }
}
