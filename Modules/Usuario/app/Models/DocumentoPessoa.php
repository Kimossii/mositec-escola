<?php

namespace Modules\Usuario\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Core\Traits\SincronizaEstadoDescricao;

class DocumentoPessoa extends Model
{
    use HasFactory, RegistaAutoria, SincronizaEstadoDescricao, SoftDeletes;

    protected $table = 'documentos_pessoas';

    protected $fillable = [
        'dados_pessoa_id',
        'tipo_documento_id',
        'numero_documento',
        'data_emissao',
        'data_validade',
        'nome_original',
        'caminho',
        'mime_type',
        'tamanho',
        'observacoes',
        'estado',
        'estado_descricao',
        'criado_por',
        'editado_por',
    ];

    protected $attributes = [
        'estado' => 1,
    ];

    protected $casts = [
        'data_emissao' => 'date',
        'data_validade' => 'date',
        'estado' => 'integer',
        'tamanho' => 'integer',
    ];

    public function dadosPessoa(): BelongsTo
    {
        return $this->belongsTo(DadosPessoa::class, 'dados_pessoa_id');
    }

    public function tipoDocumento(): BelongsTo
    {
        return $this->belongsTo(TipoDocumento::class, 'tipo_documento_id');
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
