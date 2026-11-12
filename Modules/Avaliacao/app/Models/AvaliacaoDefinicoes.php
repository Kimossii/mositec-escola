<?php

namespace Modules\Avaliacao\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;

/**
 * Definições da avaliação que valem para toda a escola, sem depender da classe: quantas avaliações contínuas cada
 * trimestre admite e quantas, no mínimo, têm de ter nota. Uma linha por escola.
 */
class AvaliacaoDefinicoes extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;

    protected $table = 'avaliacao_definicoes';

    protected $fillable = ['max_avaliacoes', 'min_avaliacoes', 'criado_por', 'editado_por'];

    protected $casts = ['max_avaliacoes' => 'integer', 'min_avaliacoes' => 'integer'];
}
