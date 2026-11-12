<?php

namespace Modules\Avaliacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;

/** Escala qualitativa da escola (ex.: Insuficiente … Excelente). Os níveis têm código, designação e ordem. */
class AvaliacaoEscala extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;

    protected $table = 'avaliacao_escalas';

    protected $fillable = ['nome', 'criado_por', 'editado_por'];

    public function niveis(): HasMany
    {
        return $this->hasMany(AvaliacaoEscalaNivel::class, 'escala_id')->orderBy('ordem');
    }
}
