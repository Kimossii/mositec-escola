<?php

namespace Modules\Usuario\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Usuario\Database\Factories\DadosPessoalFactory;

class DadosPessoal extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $table = 'dados_pessoas';

    protected $fillable = [
        'nome_completo',
        'email',
        'telefone',
        'data_nascimento',
        'sexo',
        'numero_identificacao',
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
}
