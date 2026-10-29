<?php

namespace Modules\Docente\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Core\Traits\SincronizaEstadoDescricao;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\User;

/**
 * O docente como pessoa da escola. A conta de acesso é opcional: quando existe, é o `User` com a mesma
 * `dados_pessoa_id` (mesmo padrão do aluno), sem chave directa entre as duas tabelas.
 */
class Docente extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;
    use SincronizaEstadoDescricao;

    protected $table = 'docentes';

    protected $fillable = [
        'estabelecimento_id',
        'dados_pessoa_id',
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
        return $this->belongsTo(DadosPessoa::class, 'dados_pessoa_id');
    }

    public function atribuicoes(): HasMany
    {
        return $this->hasMany(AtribuicaoDocente::class, 'docente_id');
    }

    /** A conta do docente (relação para consultas em lote; ver também `utilizador()`). */
    public function conta(): HasOne
    {
        return $this->hasOne(User::class, 'dados_pessoa_id', 'dados_pessoa_id');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function editadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editado_por');
    }

    /** A conta do docente, se existir (o `User` do tenant com a mesma pessoa). */
    public function utilizador(): ?User
    {
        return User::query()->where('dados_pessoa_id', $this->dados_pessoa_id)->first();
    }

    public function temAcesso(): bool
    {
        return User::query()->where('dados_pessoa_id', $this->dados_pessoa_id)->exists();
    }
}
