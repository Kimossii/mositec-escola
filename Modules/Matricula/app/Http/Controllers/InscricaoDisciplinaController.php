<?php

namespace Modules\Matricula\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Aluno\Models\Aluno;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Http\Requests\AlterarEstadoInscricaoDisciplinaRequest;
use Modules\Matricula\Http\Requests\CriarInscricaoDisciplinaRequest;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Matricula\Models\Matricula;
use Modules\Matricula\Services\GestaoInscricaoDisciplinaService;

class InscricaoDisciplinaController extends Controller
{
    public function __construct(
        private GestaoInscricaoDisciplinaService $service,
    ) {
    }

    public function store(CriarInscricaoDisciplinaRequest $request, Aluno $aluno, Matricula $matricula)
    {
        $this->authorize('matricula.criar');

        $this->service->criar($matricula, $request);

        return redirect()->back()->with('success', 'Inscrição em disciplina criada com sucesso.');
    }

    public function alterarEstado(AlterarEstadoInscricaoDisciplinaRequest $request, Aluno $aluno, Matricula $matricula, InscricaoDisciplina $inscricao)
    {
        $this->authorize('matricula.editar');

        $this->service->alterarEstado(
            $inscricao,
            EstadoInscricaoDisciplinaEnum::from((int) $request->validated('estado')),
        );

        return redirect()->back()->with('success', 'Estado da inscrição actualizado com sucesso.');
    }

    public function destroy(Aluno $aluno, Matricula $matricula, InscricaoDisciplina $inscricao)
    {
        $this->authorize('matricula.eliminar');

        $this->service->eliminar($inscricao);

        return redirect()->back()->with('success', 'Inscrição eliminada com sucesso.');
    }
}
