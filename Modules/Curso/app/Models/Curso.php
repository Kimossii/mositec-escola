<?php

namespace Modules\Curso\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Core\Traits\SincronizaEstadoDescricao;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\User;

class Curso extends Model
{
    use RegistaAutoria;
    use SincronizaEstadoDescricao;

    protected $table = 'cursos';

    protected $fillable = [
        'estabelecimento_id',
        'codigo',
        'nome',
        'descricao',
        'estado',
        'estado_descricao',
        'criado_por',
        'editado_por',
    ];

    protected $attributes = [
        'estado' => 1,
    ];

    protected $casts = [
        'estado' => 'integer',
    ];

    public function estabelecimento(): BelongsTo
    {
        return $this->belongsTo(Estabelecimento::class);
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
