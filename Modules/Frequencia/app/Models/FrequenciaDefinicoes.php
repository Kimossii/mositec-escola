<?php

namespace Modules\Frequencia\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;

/** Definições da frequência da escola (uma linha por tenant): por agora, a janela de correcção do professor. */
class FrequenciaDefinicoes extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;

    public const JANELA_POR_OMISSAO = 7;

    protected $table = 'frequencia_definicoes';

    protected $fillable = ['janela_correccao_dias', 'criado_por', 'editado_por'];

    protected $casts = ['janela_correccao_dias' => 'integer'];
}
