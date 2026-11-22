<?php

namespace Modules\Licenciamento\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Licenciamento\Enums\Modalidade;

/** Plano comercial do emissor. Global (sem PertenceAoTenant). */
class Plano extends Model
{
    protected $table = 'licenciamento_planos';

    protected $fillable = [
        'nome', 'descricao', 'limite_alunos', 'limite_utilizadores',
        'tolerancia_dias', 'aviso_dias', 'modulos', 'validade_dias', 'modalidade',
    ];

    protected $casts = [
        'modulos' => 'array',
        'modalidade' => Modalidade::class,
    ];

    // O `*_descricao` do enum acompanha sempre a coluna inteira (o trait do Core está preso à coluna `estado`).
    protected static function booted(): void
    {
        static::saving(fn (self $plano) => $plano->modalidade_descricao = $plano->modalidade->label());
    }
}
