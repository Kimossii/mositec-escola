<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\User;

class Horario extends Model
{
    use HasFactory;

    protected $fillable = [
        'nome',
        'hora_inicio',
        'hora_fim',
        'estado',
        'criado_por',
        'editado_por',
    ];

    /**
     * Utilizador que criou o horário.
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoHorario::class,
            'estado' => EstadoHorario::class,
        ];
    }

    public function criadoPor()
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function editadoPor()
    {
        return $this->belongsTo(User::class, 'editado_por');
    }
}
