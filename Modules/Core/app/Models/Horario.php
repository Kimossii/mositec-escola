<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Core\Traits\SincronizaEstadoDescricao;
use Modules\Usuario\Models\User;

class Horario extends Model
{
    use HasFactory;
    use SincronizaEstadoDescricao;
    use RegistaAutoria;

    protected $fillable = [
        'nome',
        'hora_inicio',
        'hora_fim',
        'estado',
        'criado_por',
        'editado_por',
    ];

    protected $casts = [
        'hora_inicio' => 'datetime:H:i',
        'hora_fim' => 'datetime:H:i',
    ];

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function editadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editado_por');
    }
}
