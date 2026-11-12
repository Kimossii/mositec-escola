<?php

namespace Modules\Avaliacao\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Avaliacao\Enums\Decisao;
use Modules\Core\Tenancy\PertenceAoTenant;

/** Decisão final de um aluno na versão aprovada da pauta. */
class AvaliacaoDecisao extends Model
{
    use PertenceAoTenant;

    protected $table = 'avaliacao_decisoes';

    protected $fillable = ['pauta_id', 'versao', 'matricula_id', 'decisao', 'decisao_descricao'];

    protected $casts = [
        'versao' => 'integer',
        'decisao' => Decisao::class,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $d) {
            $d->decisao_descricao = $d->decisao?->label();
        });
    }
}
