<?php

namespace Modules\Usuario\Actions;

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Traits\LogService;
use Modules\Usuario\Enums\AcaoAcessoDocumento;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\DocumentoPessoa;
use Modules\Usuario\Models\User;
use Modules\Usuario\Services\LimitadorVarrimentoDocumentos;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Aplica âmbito + limite de varrimento e regista o acesso (ou a negação). Aborta com 403/429 se recusar. */
class AutorizarAcessoDocumentoPessoaAction
{
    use LogService;

    public function __construct(
        private LimitadorVarrimentoDocumentos $limitador,
        private RegistarAcessoDocumentoPessoaAction $registar,
    ) {
    }

    public function executar(User $utilizador, Request $request, DadosPessoa $dona, AcaoAcessoDocumento $acao, ?DocumentoPessoa $documento = null): void
    {
        $this->comLog('Autorizar acesso a documento pessoa', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($utilizador, $request, $dona, $acao, $documento));
    }

    private function executarSemRegisto(User $utilizador, Request $request, DadosPessoa $dona, AcaoAcessoDocumento $acao, ?DocumentoPessoa $documento): void
    {
        if (! Gate::forUser($utilizador)->allows('verDocumentosPessoa', $dona)) {
            $this->registar->executar($utilizador, $dona, AcaoAcessoDocumento::Negado, $documento, $request);

            throw new HttpException(403, 'Sem acesso aos documentos desta pessoa.');
        }

        try {
            $this->limitador->registar($utilizador, $dona);
        } catch (ThrottleRequestsException $excecao) {
            $this->registar->executar($utilizador, $dona, AcaoAcessoDocumento::Negado, $documento, $request);

            throw $excecao;
        }

        $this->registar->executar($utilizador, $dona, $acao, $documento, $request);
    }
}
