<?php

namespace Modules\Docente\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Core\Exportacao\Exportador;
use Modules\Docente\Http\Requests\AlterarEstadoDocenteRequest;
use Modules\Docente\Http\Requests\AtualizarDocenteRequest;
use Modules\Docente\Http\Requests\CriarAcessoDocenteRequest;
use Modules\Docente\Http\Requests\CriarDocenteRequest;
use Modules\Docente\Models\Docente;
use Modules\Docente\Services\DocenteConsultaService;
use Modules\Docente\Services\ExportacaoDeDocentesService;
use Modules\Docente\Services\GestaoDocenteService;

class DocenteController extends Controller
{
    public function __construct(
        private GestaoDocenteService $service,
        private DocenteConsultaService $consulta,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('docente.ver');

        $filtros = $request->only(['pesquisa', 'estado', 'acesso']);

        return Inertia::render('Docente/Docentes/Index', [
            'docentes' => $this->consulta->listar($filtros),
            // Diferido: os cartões chegam depois de a lista abrir.
            'indicadores' => Inertia::defer(fn () => $this->consulta->indicadores()),
            'filtros' => $filtros,
        ]);
    }

    /** Exporta a lista (Excel, PDF, CSV ou ZIP) com os mesmos filtros do ecrã. */
    public function exportar(Request $request, ExportacaoDeDocentesService $exportacao, Exportador $exportador)
    {
        $this->authorize('docente.exportar');

        $dados = $request->validate([
            'formato' => ['required', 'string'],
            'pesquisa' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', 'in:0,1'],
            'acesso' => ['nullable', 'in:com,sem'],
        ]);

        return $exportador->responder($exportacao->tabela($dados), $dados['formato'], 'docentes');
    }

    public function store(CriarDocenteRequest $request)
    {
        $this->authorize('docente.criar');

        $this->service->criar($request);

        return redirect()->back()->with('success', 'Docente criado com sucesso.');
    }

    public function update(AtualizarDocenteRequest $request, Docente $docente)
    {
        $this->authorize('docente.editar');

        $this->service->atualizar($docente, $request);

        return redirect()->back()->with('success', 'Docente atualizado com sucesso.');
    }

    public function alterarEstado(AlterarEstadoDocenteRequest $request, Docente $docente)
    {
        $this->authorize('docente.editar');

        $this->service->alterarEstado($docente, (int) $request->validated('estado'));

        return redirect()->back()->with('success', 'Estado do docente atualizado com sucesso.');
    }

    /** «Criar acesso»: conta de utilizador (perfil Professor) para o docente. Autorização no FormRequest. */
    public function criarAcesso(CriarAcessoDocenteRequest $request, Docente $docente)
    {
        $this->service->criarAcesso($docente, $request);

        return redirect()->back()->with('success', 'Acesso do docente criado com sucesso.');
    }

    public function destroy(Docente $docente)
    {
        $this->authorize('docente.eliminar');

        $this->service->eliminar($docente);

        return redirect()->back()->with('success', 'Docente eliminado com sucesso.');
    }
}
