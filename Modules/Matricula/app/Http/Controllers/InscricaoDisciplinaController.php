<?php

namespace Modules\Matricula\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Aluno\Models\Aluno;
use Modules\Matricula\Actions\InscreverDisciplinasAutomaticamenteAction;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Http\Requests\AlterarEstadoInscricaoDisciplinaRequest;
use Modules\Matricula\Http\Requests\CriarInscricaoDisciplinaRequest;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Matricula\Models\Matricula;
use Modules\Matricula\Services\GestaoInscricaoDisciplinaService;
use Modules\Matricula\Services\MatriculaConsultaService;
use Modules\Turma\Models\Turma;

class InscricaoDisciplinaController extends Controller
{
    public function __construct(
        private GestaoInscricaoDisciplinaService $service,
        private MatriculaConsultaService $consulta,
        private InscreverDisciplinasAutomaticamenteAction $inscreverDisciplinas,
    ) {
    }

    /**
     * Consulta o Plano Curricular aplicável a uma Turma (mesma resolução da
     * Task 3, reaproveitada aqui) — usado no formulário de Nova Matrícula
     * para mostrar as disciplinas previstas antes de confirmar. Devolve
     * `null` se não houver plano confirmado.
     */
    public function planoCurricular(Turma $turma)
    {
        $this->authorize('matricula.ver');

        $plano = $this->inscreverDisciplinas->resolverPlanoCurricular($turma);
        $plano?->loadMissing('disciplinas.disciplina');

        // response()->json(null) devolve "{}" em vez de "null" (comportamento
        // do próprio JsonResponse do Laravel) — envolver num objecto evita
        // essa ambiguidade e deixa o "sem plano" inequívoco do lado do cliente.
        return response()->json(['plano' => $plano]);
    }

    public function listar(Aluno $aluno, Matricula $matricula)
    {
        $this->authorize('matricula.ver');

        return response()->json($this->consulta->listarDisciplinasDaMatricula($matricula));
    }

    public function disponiveis(Aluno $aluno, Matricula $matricula)
    {
        $this->authorize('matricula.ver');

        return response()->json($this->consulta->disciplinasDisponiveisParaInscricao($matricula));
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
