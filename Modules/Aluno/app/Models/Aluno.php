<?php

namespace Modules\Aluno\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Core\Traits\SincronizaEstadoDescricao;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\DadosPessoal;
use Modules\Usuario\Models\User;

class Aluno extends Model
{
    use RegistaAutoria;
    use SincronizaEstadoDescricao;

    protected $table = 'alunos';

    protected $fillable = [
        'estabelecimento_id',
        'dados_pessoa_id',
        'numero_matricula',
        'estado',
        'estado_descricao',
        'criado_por',
        'editado_por',
    ];

    protected $attributes = [
        'estado' => 1,
    ];

    protected $casts = [
        'estado' => 'integer',
    ];

    public function estabelecimento(): BelongsTo
    {
        return $this->belongsTo(Estabelecimento::class);
    }

    public function dadosPessoa(): BelongsTo
    {
        return $this->belongsTo(DadosPessoal::class, 'dados_pessoa_id');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function editadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editado_por');
    }
}
