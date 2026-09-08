<?php

namespace Modules\Turma\app\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Estabelecimento\Models\Estabelecimento;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NivelAcademico extends Model
{
    protected $table = 'niveis_academicos';

    protected $fillable = [
        'estabelecimento_id',
        'codigo',
        'nome',
        'ordem',
        'estado',
        'estado_descricao',
        'criado_por',
        'editado_por',
    ];

    protected $casts = [
        'estado' => 'integer',
        'ordem' => 'integer',
    ];

    public function estabelecimento(): BelongsTo
    {
        return $this->belongsTo(Estabelecimento::class, 'estabelecimento_id');
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
