<?php

namespace Modules\Aluno\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Core\Traits\SincronizaEstadoDescricao;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\User;
use Modules\Aluno\Models\AlunoEnquadramentoAcademico;

class Aluno extends Model
{
    use RegistaAutoria;
    use SincronizaEstadoDescricao;

    protected $table = 'alunos';

    protected $fillable = [
        'estabelecimento_id',
        'dados_pessoa_id',
        'numero_matricula',
        'foto_path',
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

    protected $appends = ['foto_url'];

    protected function fotoUrl(): Attribute
    {
        return Attribute::get(
            fn () => $this->foto_path ? Storage::disk('public')->url($this->foto_path) : null,
        );
    }

    public function estabelecimento(): BelongsTo
    {
        return $this->belongsTo(Estabelecimento::class);
    }

    public function dadosPessoa(): BelongsTo
    {
        return $this->belongsTo(DadosPessoa::class, 'dados_pessoa_id');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function editadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editado_por');
    }

    public function enquadramentosAcademicos(): HasMany
    {
        return $this->hasMany(AlunoEnquadramentoAcademico::class);
    }
}
