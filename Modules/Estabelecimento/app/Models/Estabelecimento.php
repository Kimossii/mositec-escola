<?php

namespace Modules\Estabelecimento\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Estabelecimento\Enums\TipoEnsinoEnum;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;

class Estabelecimento extends Model
{
    use HasFactory;
    use PertenceAoTenant;
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
        'configurado_em' => 'datetime',
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
     * O perfil institucional do tenant corrente.
     *
     * Não é mecanismo de isolamento (isso é o scope do tenant). Lê com scope e
     * guarda o resultado no contexto, por isso custa uma consulta por pedido.
     * Sem tenant resolvido lança TenantNaoResolvido; dentro de um tenant nunca
     * é nulo, porque o estabelecimento nasce no provisioning.
     */
    public static function current(): self
    {
        return app(TenantContext::class)->lembrar(
            'estabelecimento.actual',
            fn () => static::query()->firstOrFail(),
        );
    }

    public function estaConfigurado(): bool
    {
        return $this->configurado_em !== null;
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
