<?php

namespace Modules\Avaliacao\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Avaliacao\Enums\Decisao;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;

/** Decisão de progressão de uma criança quando a regra da classe é «decidida criança a criança». */
class AvaliacaoProgressao extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;

    protected $table = 'avaliacao_progressoes';

    protected $fillable = ['matricula_id', 'decisao', 'decisao_descricao', 'motivo', 'criado_por', 'editado_por'];

    protected $casts = ['decisao' => Decisao::class];

    protected static function booted(): void
    {
        static::saving(function (self $p) {
            $p->decisao_descricao = $p->decisao?->label();
        });
    }
}
