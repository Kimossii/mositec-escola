<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\PertenceAoTenant;

/** Linha de uma importação. Leva o tenant_id da Importacao a que pertence (preenchido na inserção em bloco). */
class ImportacaoLinha extends Model
{
    use PertenceAoTenant;

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
