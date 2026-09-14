<?php

namespace Modules\Estabelecimento\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Modules\Estabelecimento\Enums\TipoEnsinoEnum;
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
        'tipo_ensino',
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
        'tipo_ensino' => TipoEnsinoEnum::class,
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

    public function etapasEnsino(): HasMany
    {
        return $this->hasMany(EstabelecimentoEtapaEnsino::class, 'estabelecimento_id');
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

            // Ao contrário de `tipo`, `tipo_ensino` tem default a nível de BD
            // (coluna adicionada depois, para não quebrar registos/chamadas
            // existentes que ainda não conhecem este campo). Só sincroniza
            // a descrição quando o valor é explicitamente definido, para não
            // sobrepor o default da coluna com `null`.
            if ($estabelecimento->tipo_ensino !== null) {
                $estabelecimento->tipo_ensino_descricao = $estabelecimento->tipo_ensino->label();
            }
        });
    }
}
