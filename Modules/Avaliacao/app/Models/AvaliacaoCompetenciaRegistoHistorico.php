<?php

namespace Modules\Avaliacao\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\PertenceAoTenant;

class AvaliacaoCompetenciaRegistoHistorico extends Model
{
    use PertenceAoTenant;

    protected $table = 'avaliacao_competencia_registos_historico';

    protected $fillable = ['registo_id', 'escala_nivel_anterior_id', 'escala_nivel_novo_id', 'fora_do_prazo', 'motivo', 'criado_por'];

    protected $casts = ['fora_do_prazo' => 'boolean'];
}
