<?php

namespace Modules\Autenticacao\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Autenticacao\Actions\TerminarOutrasSessoesAction;
use Modules\Autenticacao\Actions\TerminarSessaoAction;
use Modules\Autenticacao\Service\SessoesDoUtilizadorService;

class DefinicoesController extends Controller
{
    private const ABAS = ['perfil', 'seguranca', 'aparencia', 'permissoes'];

    public function __construct(private SessoesDoUtilizadorService $sessoes)
    {
    }

    public function index(Request $request)
    {
        $utilizador = $request->user();

        return Inertia::render('Autenticacao/Definicoes', [
            'aba' => in_array($request->query('aba'), self::ABAS, true) ? $request->query('aba') : 'perfil',
            'membroDesde' => $utilizador->created_at?->toDateString(),
            'sessoes' => $this->sessoes->listar($utilizador, $request->session()->getId()),
        ]);
    }

    public function terminarOutrasSessoes(Request $request, TerminarOutrasSessoesAction $acao)
    {
        $acao->executar($request->user(), $request->session()->getId());

        return back();
    }

    public function terminarSessao(Request $request, string $referencia, TerminarSessaoAction $acao)
    {
        abort_unless($acao->executar($request->user(), $referencia, $request->session()->getId()), 404);

        return back();
    }
}
