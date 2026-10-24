<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\PertenceAoTenant;

/** Nível ou curso a que se aplica um item obrigatório do catálogo (sem alvos = todos os alunos). */
class CatalogoAlvo extends Model
{
    use PertenceAoTenant;

    protected $table = 'catalogo_alvos';

    protected $fillable = ['item_tipo', 'item_id', 'nivel_academico_id', 'curso_id'];

    protected $hidden = ['tenant_id'];
}
