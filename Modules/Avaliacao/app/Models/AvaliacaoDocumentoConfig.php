<?php

namespace Modules\Avaliacao\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;

/** Texto do cabeçalho e nomes dos responsáveis nas pautas e mini-pautas da escola. Uma linha por escola. */
class AvaliacaoDocumentoConfig extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;

    protected $table = 'avaliacao_documento_config';

    protected $fillable = ['linhas_extra', 'lema', 'visto_cargo', 'visto_nome', 'criado_por', 'editado_por'];
}
