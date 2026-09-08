<?php

namespace Modules\Infraestrutura\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Infraestrutura\Enums\EstadoSala;
use Modules\Infraestrutura\Enums\TipoSala;
use Modules\Usuario\Models\User;

class Sala extends Model
{
    use HasFactory;
    use SoftDeletes;
    use RegistaAutoria;

    protected $table = 'salas';

    protected $fillable = [
        'estabelecimento_id',
        'codigo',
        'nome',
        'tipo',
        'capacidade',
        'localizacao',
        'observacoes',
        'estado',
        'criado_por',
        'editado_por',
    ];

    protected $attributes = [
        'estado' => 0, // EstadoSala::ATIVA
    ];

    protected $casts = [
        'tipo' => TipoSala::class,
        'estado' => EstadoSala::class,
        'capacidade' => 'integer',
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

    protected static function booted(): void
    {
        static::saving(function (Sala $sala) {
            $sala->tipo_descricao = $sala->tipo instanceof TipoSala
                ? $sala->tipo->label()
                : TipoSala::from((int) $sala->tipo)->label();

            $sala->estado_descricao = $sala->estado instanceof EstadoSala
                ? $sala->estado->label()
                : EstadoSala::from((int) $sala->estado)->label();
        });
    }
}
