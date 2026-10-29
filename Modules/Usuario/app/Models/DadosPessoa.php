<?php

namespace Modules\Usuario\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Tenancy\PertenceAoTenant;
// use Modules\Usuario\Database\Factories\DadosPessoalFactory;

class DadosPessoa extends Model
{
    use HasFactory;
    use PertenceAoTenant;

    /**
     * The attributes that are mass assignable.
     */
    protected $table = 'dados_pessoas';

    protected $fillable = [
        'nome_completo',
        'email',
        'telefone',
        'telefone_alternativo',
        'data_nascimento',
        'sexo',
        'numero_identificacao',
        'provincia_nascimento',
        'municipio_nascimento',
        'tipo_pessoa',
    ];

    protected $casts = [
        'data_nascimento' => 'date',
    ];

    // Constantes para sexo
    const SEXO_NAO_ESPECIFICADO = 0;
    const SEXO_MASCULINO = 1;
    const SEXO_FEMININO = 2;

    // Constantes para tipo de pessoa
    const TIPO_ALUNO = 0;
    const TIPO_PROFESSOR = 1;
    const TIPO_FUNCIONARIO = 2;
    const TIPO_OUTRO = 3;

    /**
     * O BI é único por escola entre todos os tipos de pessoa (aluno, docente, ...); para o ser de facto, grava-se
     * sem espaços e em maiúsculas ("005 678 901 la042" e "005678901LA042" são a mesma pessoa).
     */
    public static function normalizarNumeroIdentificacao(string $valor): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', '', $valor));
    }

    public function setNumeroIdentificacaoAttribute(?string $valor): void
    {
        $this->attributes['numero_identificacao'] = $valor === null ? null : self::normalizarNumeroIdentificacao($valor);
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(DocumentoPessoa::class, 'dados_pessoa_id');
    }
}
