<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\PertenceAoTenant;

/** Exportação pesada gerada em fila para um utilizador («Os meus ficheiros»). O ficheiro vive em storage/app/exportacoes. */
class PedidoExportacao extends Model
{
    use PertenceAoTenant;

    public const PENDENTE = 0;

    public const PRONTO = 1;

    public const ERRO = 2;

    /** Pedidos a preparar por utilizador e ficheiros prontos que se guardam por utilizador. */
    public const MAXIMO_PENDENTES = 3;

    public const MAXIMO_GUARDADOS = 20;

    protected $table = 'pedidos_exportacao';

    public function partilhas(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PartilhaExportacao::class, 'pedido_exportacao_id');
    }


    protected $fillable = ['user_id', 'titulo', 'formato', 'nome', 'permissao', 'linhas', 'tamanho', 'estado', 'caminho', 'erro', 'concluido_em', 'visto_em'];

    protected $casts = ['concluido_em' => 'datetime', 'visto_em' => 'datetime'];
}
