<?php

namespace Modules\Avaliacao\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\PertenceAoTenant;

/** O que foi impresso na pauta qualitativa aprovada: {codigo, designacao} por trimestre e MFD. Nunca se altera. */
class AvaliacaoPautaLinhaQualitativa extends Model
{
    use PertenceAoTenant;

    protected $table = 'avaliacao_pauta_linhas_qualitativas';

    protected $fillable = ['pauta_id', 'versao', 'inscricao_disciplina_id', 'mt1', 'mt2', 'mt3', 'mfd'];

    protected $casts = ['versao' => 'integer', 'mt1' => 'array', 'mt2' => 'array', 'mt3' => 'array', 'mfd' => 'array'];
}
