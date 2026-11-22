<?php

namespace Modules\Usuario\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Modules\Usuario\Enums\AcaoAcessoDocumento;
use Modules\Usuario\Http\Requests\FiltrarAcessosDocumentosRequest;
use Modules\Usuario\Services\GestaoAcessoDocumentoPessoaService;

class AcessoDocumentoPessoaController extends Controller
{
    public function __construct(private GestaoAcessoDocumentoPessoaService $service)
    {
    }

    public function index(FiltrarAcessosDocumentosRequest $request)
    {
        $this->authorize('acesso-documento-pessoa.ver');
        $filtros = $request->filtros();

        return Inertia::render('Usuario/AcessosDocumentos/Index', [
            'acessos' => $this->service->listar($filtros),
            'filtros' => $filtros,
            'acoes' => collect(AcaoAcessoDocumento::cases())->map(fn ($a) => ['valor' => $a->value, 'rotulo' => $a->label()])->all(),
        ]);
    }
}
