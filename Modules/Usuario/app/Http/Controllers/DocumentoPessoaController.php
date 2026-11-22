<?php

namespace Modules\Usuario\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Usuario\Actions\AutorizarAcessoDocumentoPessoaAction;
use Modules\Usuario\DTO\DocumentoPessoaDTO;
use Modules\Usuario\Http\Requests\GuardarDocumentoPessoaRequest;
use Modules\Usuario\Enums\AcaoAcessoDocumento;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\DocumentoPessoa;
use Modules\Usuario\Services\GestaoDocumentoPessoaService;

class DocumentoPessoaController extends Controller
{
    public function __construct(
        private GestaoDocumentoPessoaService $service,
        private AutorizarAcessoDocumentoPessoaAction $acesso,
    ) {
    }

    public function index(Request $request, DadosPessoa $dadosPessoa)
    {
        $this->authorize('documento-pessoa.ver');
        $this->acesso->executar($request->user(), $request, $dadosPessoa, AcaoAcessoDocumento::Listar);

        return response()->json([
            'documentos' => $this->service->listar($dadosPessoa),
        ]);
    }

    public function inativos(Request $request, DadosPessoa $dadosPessoa)
    {
        $this->authorize('documento-pessoa.ver');
        $this->acesso->executar($request->user(), $request, $dadosPessoa, AcaoAcessoDocumento::Listar);

        return response()->json([
            'documentos' => $this->service->listarInativos($dadosPessoa),
        ]);
    }

    public function store(GuardarDocumentoPessoaRequest $request, DadosPessoa $dadosPessoa)
    {
        $this->authorize('documento-pessoa.criar');

        $dto = DocumentoPessoaDTO::fromRequest($request);
        $this->service->adicionar($dadosPessoa, $dto, $request->file('ficheiro'));

        return redirect()->back()->with('success', 'Documento adicionado com sucesso.');
    }

    public function destroy(DocumentoPessoa $documento)
    {
        $this->authorize('documento-pessoa.eliminar');

        $this->service->remover($documento);

        return redirect()->back()->with('success', 'Documento removido com sucesso.');
    }

    public function alternarEstado(DocumentoPessoa $documento)
    {
        $this->authorize('documento-pessoa.editar');

        $this->service->alternarEstado($documento);

        return redirect()->back()->with('success', 'Estado do documento atualizado com sucesso.');
    }

    public function download(Request $request, DocumentoPessoa $documento)
    {
        $this->authorize('documento-pessoa.ver');
        $this->acesso->executar($request->user(), $request, $documento->dadosPessoa, AcaoAcessoDocumento::Download, $documento);

        return $this->service->download($documento);
    }

    public function visualizar(Request $request, DocumentoPessoa $documento)
    {
        $this->authorize('documento-pessoa.ver');
        $this->acesso->executar($request->user(), $request, $documento->dadosPessoa, AcaoAcessoDocumento::Visualizar, $documento);

        return $this->service->visualizar($documento);
    }

    public function tipos()
    {
        $this->authorize('documento-pessoa.criar');

        return response()->json([
            'tipos' => $this->service->tiposDisponiveis(),
        ]);
    }
}
