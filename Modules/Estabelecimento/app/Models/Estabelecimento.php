<?php

namespace Modules\Estabelecimento\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;

class Estabelecimento extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'estabelecimentos';

    protected $fillable = [
        'nome',
        'nome_abreviado',
        'tipo',
        'nif',
        'codigo_mined',
        'numero_alvara',
        'email',
        'telefone',
        'telefone_alternativo',
        'website',
        'endereco',
        'caixa_postal',
        'municipio',
        'provincia',
        'responsavel_nome',
        'responsavel_cargo',
        'ano_fundacao',
        'logotipo_path',
        'observacoes',
        'is_active',
    ];

    protected $casts = [
        'tipo' => TipoEstabelecimentoEnum::class,
        'ano_fundacao' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $appends = ['logotipo_url'];

    protected function logotipoUrl(): Attribute
    {
        return Attribute::get(
            fn () => $this->logotipo_path ? Storage::disk('public')->url($this->logotipo_path) : null,
        );
    }

    /**
     * Devolve o estabelecimento actualmente activo.
     *
     * Preparado para um futuro cenário multi-estabelecimento (tenancy):
     * cada estabelecimento é um registo independente e `current()` apenas
     * resolve qual deles está activo no contexto actual.
     */
    public static function current(): ?self
    {
        return static::where('is_active', true)->first();
    }

    protected static function booted(): void
    {
        static::saving(function (Estabelecimento $estabelecimento) {
            $estabelecimento->tipo_descricao = $estabelecimento->tipo?->label();
        });
    }
}
