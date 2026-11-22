<?php

namespace Modules\Usuario\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Usuario\Enums\AcaoAcessoDocumento;

/** Registo de auditoria: só se insere. Nunca se edita nem se apaga. */
class AcessoDocumentoPessoa extends Model
{
    use PertenceAoTenant;

    public const UPDATED_AT = null;

    protected $table = 'acessos_documentos_pessoa';

    protected $fillable = [
        'user_id',
        'dados_pessoa_id',
        'utilizador_nome',
        'pessoa_nome',
        'documento_pessoa_id',
        'acao',
        'acao_descricao',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'acao' => AcaoAcessoDocumento::class,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $acesso) {
            $acesso->acao_descricao = $acesso->acao?->label();
        });
        static::updating(fn () => throw new LogicException('Os registos de acesso não se editam.'));
        static::deleting(fn () => throw new LogicException('Os registos de acesso não se apagam.'));
    }

    public function utilizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function dadosPessoa(): BelongsTo
    {
        return $this->belongsTo(DadosPessoa::class, 'dados_pessoa_id');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoPessoa::class, 'documento_pessoa_id');
    }
}
