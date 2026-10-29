<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Linha de uma importação. Sem tenant próprio: chega-se sempre por Importacao, que é do tenant. */
class ImportacaoLinha extends Model
{
    public const PENDENTE = 0;

    public const VALIDA = 1;

    public const ERRO = 2;

    public const IGNORADA = 3;

    public const IMPORTADA = 4;

    public $timestamps = false;

    protected $table = 'importacao_linhas';

    protected $fillable = ['importacao_id', 'linha', 'dados', 'estado', 'erros', 'registo_id'];

    protected $casts = ['dados' => 'array', 'erros' => 'array'];

    public function importacao(): BelongsTo
    {
        return $this->belongsTo(Importacao::class);
    }
}
