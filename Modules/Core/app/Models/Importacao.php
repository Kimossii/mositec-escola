<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Tenancy\PertenceAoTenant;

/** Uma importação em massa (ficheiro → linhas intermédias → registos). Só o dono a vê e confirma. */
class Importacao extends Model
{
    use PertenceAoTenant;

    public const CARREGADA = 0;

    public const A_VALIDAR = 1;

    public const VALIDADA = 2;

    public const A_IMPORTAR = 3;

    public const CONCLUIDA = 4;

    public const FALHADA = 5;

    public const CANCELADA = 6;

    public const ESTADOS = [
        self::CARREGADA => 'Carregada',
        self::A_VALIDAR => 'A validar…',
        self::VALIDADA => 'Validada, a aguardar confirmação',
        self::A_IMPORTAR => 'A importar…',
        self::CONCLUIDA => 'Concluída',
        self::FALHADA => 'Falhou',
        self::CANCELADA => 'Cancelada',
    ];

    protected $table = 'importacoes';

    protected $fillable = ['user_id', 'entidade', 'nome_ficheiro', 'caminho', 'estado', 'total', 'validas', 'ignoradas', 'com_erro', 'importadas', 'erro_geral', 'concluido_em'];

    protected $casts = ['concluido_em' => 'datetime'];

    public function linhas(): HasMany
    {
        return $this->hasMany(ImportacaoLinha::class);
    }

    public function emCurso(): bool
    {
        return in_array($this->estado, [self::CARREGADA, self::A_VALIDAR, self::A_IMPORTAR], true);
    }
}
