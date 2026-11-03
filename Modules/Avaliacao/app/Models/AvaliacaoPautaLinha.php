<?php

namespace Modules\Avaliacao\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\PertenceAoTenant;

/** Valores impressos na pauta para um aluno numa disciplina, na versão aprovada. Nunca se altera. */
class AvaliacaoPautaLinha extends Model
{
    use PertenceAoTenant;

    protected $table = 'avaliacao_pauta_linhas';

    protected $fillable = ['pauta_id', 'versao', 'inscricao_disciplina_id', 'mt1', 'mt2', 'mt3', 'ne', 'mfd_exacta', 'mfd', 'mf_exacta', 'mf', 'aprovado'];

    protected $casts = [
        'versao' => 'integer',
        'mt1' => 'decimal:4',
        'mt2' => 'decimal:4',
        'mt3' => 'decimal:4',
        'ne' => 'decimal:4',
        'mfd_exacta' => 'decimal:10',
        'mfd' => 'decimal:4',
        'mf_exacta' => 'decimal:10',
        'mf' => 'decimal:4',
        'aprovado' => 'boolean',
    ];
}
