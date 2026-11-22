<?php

namespace Modules\Usuario\Actions;

use Illuminate\Http\Request;
use Modules\Core\Traits\LogService;
use Modules\Usuario\Enums\AcaoAcessoDocumento;
use Modules\Usuario\Models\AcessoDocumentoPessoa;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\DocumentoPessoa;
use Modules\Usuario\Models\User;
use Throwable;

class RegistarAcessoDocumentoPessoaAction
{
    use LogService;

    /** Nunca lança: falhar a auditoria não pode derrubar nem desbloquear o pedido. */
    public function executar(User $utilizador, DadosPessoa $dona, AcaoAcessoDocumento $acao, ?DocumentoPessoa $documento, Request $request): void
    {
        try {
            AcessoDocumentoPessoa::create([
                'user_id' => $utilizador->id,
                'dados_pessoa_id' => $dona->id,
                'utilizador_nome' => $utilizador->name,
                'pessoa_nome' => $dona->nome_completo,
                'documento_pessoa_id' => $documento?->id,
                'acao' => $acao,
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            ]);
        } catch (Throwable $excecao) {
            $this->logException($excecao, 'Falha ao registar acesso a documento pessoal', [
                'user_id' => $utilizador->id,
                'dados_pessoa_id' => $dona->id,
                'acao' => $acao->value,
            ]);
        }
    }
}
