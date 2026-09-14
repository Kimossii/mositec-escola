<?php

namespace Modules\Turma\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Modules\Core\Enums\Estado;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Turma\Http\Requests\AlterarEstadoNivelAcademicoRequest;
use Modules\Turma\Http\Requests\AtualizarNivelAcademicoRequest;
use Modules\Turma\Http\Requests\CriarNivelAcademicoRequest;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Services\GestaoNivelAcademicoService;
use Modules\Turma\Services\NivelAcademicoConsultaService;

class NivelAcademicoController extends Controller
{
    public function __construct(
        private GestaoNivelAcademicoService $service,
        private NivelAcademicoConsultaService $consulta,
    ) {}

    public function index()
    {
        $this->authorize('turmas.ver');

        return Inertia::render('Turma/NiveisAcademicos/Index', [
            'niveisAcademicos' => $this->consulta->listar(),
            'etapasEnsino' => Estabelecimento::current()?->etapasEnsino()
                ->get(['etapa_ensino', 'etapa_ensino_descricao']) ?? [],
        ]);
    }

    public function show(NivelAcademico $nivelAcademico)
    {
        $this->authorize('turmas.ver');

        return Inertia::render('Turma/NiveisAcademicos/Show', [
            'nivelAcademico' => $this->consulta->comRelacoes($nivelAcademico),
        ]);
    }

    public function store(CriarNivelAcademicoRequest $request)
    {
        $this->authorize('turmas.criar');

        $this->service->criar($request);

        return redirect()->back()->with('success', 'Nível académico criado com sucesso.');
    }

    public function update(AtualizarNivelAcademicoRequest $request, NivelAcademico $nivelAcademico)
    {
        $this->authorize('turmas.editar');

        $this->service->atualizar($nivelAcademico, $request);

        return redirect()->back()->with('success', 'Nível académico atualizado com sucesso.');
    }

    public function alterarEstado(AlterarEstadoNivelAcademicoRequest $request, NivelAcademico $nivelAcademico)
    {
        $this->authorize('turmas.editar');

        $this->service->alterarEstado($nivelAcademico, Estado::from((int) $request->validated('estado')));

        return redirect()->back()->with('success', 'Estado do nível académico atualizado com sucesso.');
    }

    public function destroy(NivelAcademico $nivelAcademico)
    {
        $this->authorize('turmas.eliminar');

        $this->service->eliminar($nivelAcademico);

        return redirect()->back()->with('success', 'Nível académico eliminado com sucesso.');
    }
}
