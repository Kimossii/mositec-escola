<?php

namespace Modules\Matricula\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Aluno\Models\Aluno;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Http\Requests\AlterarEstadoMatriculaRequest;
use Modules\Matricula\Http\Requests\AtualizarMatriculaRequest;
use Modules\Matricula\Http\Requests\CriarMatriculaRequest;
use Modules\Matricula\Http\Requests\RenovarMatriculaRequest;
use Modules\Matricula\Http\Requests\RenovarMatriculasEmMassaRequest;
use Modules\Matricula\Models\Matricula;
use Modules\Matricula\Services\GestaoMatriculaService;
use Modules\Matricula\Services\MatriculaConsultaService;

class MatriculaController extends Controller
{
    public function __construct(
        private GestaoMatriculaService $service,
        private MatriculaConsultaService $consulta,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('matricula.ver');

        $filtros = $request->only(['turma_id', 'ano_lectivo_id', 'estado', 'pesquisa']);
        // Por omissão mostra só o ano lectivo activo — mas só quando o
        // pedido não indicou nenhum (incluindo "todos", que chega como
        // ano_lectivo_id vazio); assim escolher "Todos" fica sempre
        // possível e não é substituído de volta pelo ano activo.
        if (! $request->has('ano_lectivo_id')) {
            $filtros['ano_lectivo_id'] = AnoLectivo::current(Estabelecimento::current()?->id)?->id;
        }

        return Inertia::render('Matricula/Index', [
            'matriculas' => $this->consulta->listarTodas($filtros),
            'turmasDisponiveis' => $this->consulta->turmasDisponiveis(),
            'anosLectivosDisponiveis' => $this->consulta->anosLectivosDisponiveis(),
            'filtros' => $filtros,
        ]);
    }

    public function store(CriarMatriculaRequest $request, Aluno $aluno)
    {
        $this->authorize('matricula.criar');

        $this->service->criar($aluno, $request);

        return redirect()->back()->with('success', 'Matrícula criada com sucesso.');
    }

    public function update(AtualizarMatriculaRequest $request, Aluno $aluno, Matricula $matricula)
    {
        $this->authorize('matricula.editar');

        $this->service->atualizar($matricula, $request);

        return redirect()->back()->with('success', 'Matrícula actualizada com sucesso.');
    }

    public function alterarEstado(AlterarEstadoMatriculaRequest $request, Aluno $aluno, Matricula $matricula)
    {
        $this->authorize('matricula.editar');

        $this->service->alterarEstado(
            $matricula,
            EstadoMatriculaEnum::from((int) $request->validated('estado')),
            $request->validated('data_fim'),
        );

        return redirect()->back()->with('success', 'Estado da matrícula actualizado com sucesso.');
    }

    public function renovar(RenovarMatriculaRequest $request, Aluno $aluno, Matricula $matricula)
    {
        $this->authorize('matricula.criar');

        $this->service->renovar($matricula, $request->validated('turma_id'));

        return redirect()->back()->with('success', 'Matrícula renovada com sucesso.');
    }

    public function destroy(Aluno $aluno, Matricula $matricula)
    {
        $this->authorize('matricula.eliminar');

        $this->service->eliminar($matricula);

        return redirect()->back()->with('success', 'Matrícula eliminada com sucesso.');
    }

    public function historico(Aluno $aluno, Matricula $matricula)
    {
        $this->authorize('matricula.ver');

        return response()->json([
            'historico' => $this->consulta->historicoDaMatricula($matricula),
        ]);
    }

    public function renovarEmMassa(RenovarMatriculasEmMassaRequest $request)
    {
        $this->authorize('matricula.criar');

        $resultado = $this->service->renovarEmMassa($request->validated('matricula_ids'));

        $mensagem = "{$resultado['sucesso']} matrícula(s) renovada(s) com sucesso.";
        if (count($resultado['falhas']) > 0) {
            $mensagem .= ' ' . count($resultado['falhas']) . ' não puderam ser renovadas (turma seguinte não encontrada ou estado inválido).';
        }

        return redirect()->back()->with('success', $mensagem);
    }
}
