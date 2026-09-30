<?php

namespace Modules\Usuario\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Traits\SincronizaEstadoDescricao;

class TipoDocumento extends Model
{
    use HasFactory, SincronizaEstadoDescricao;

    protected $table = 'tipos_documentos';

    protected $fillable = [
        'nome',
        'slug',
        'estado',
        'estado_descricao',
    ];

    protected $attributes = [
        'estado' => 1,
    ];

    protected $casts = [
        'estado' => 'integer',
    ];

    public function documentos(): HasMany
    {
        return $this->hasMany(DocumentoPessoa::class, 'tipo_documento_id');
    }
}
