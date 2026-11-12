<?php

namespace Modules\Avaliacao\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;

/** A medida tomada para uma negativa: uma por inscrição do aluno na disciplina. A pauta só se aprova com todas tratadas. */
class AvaliacaoNegativaTratada extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;

    protected $table = 'avaliacao_negativas_tratadas';

    protected $fillable = ['inscricao_disciplina_id', 'medida', 'criado_por', 'editado_por'];
}
