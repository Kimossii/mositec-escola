<?php

namespace Modules\Horario\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Horario\Http\Requests\EncerrarEntradaRequest;
use Modules\Horario\Http\Requests\GuardarEntradaRequest;
use Modules\Horario\Models\GrelhaEntrada;
use Modules\Horario\Services\ConsultaGrelhaService;
use Modules\Horario\Services\GestaoGrelhaService;

class GrelhaController extends Controller
{
    private const VISTAS = ['turma', 'docente', 'sala'];

    public function __construct(
        private GestaoGrelhaService $service,
        private ConsultaGrelhaService $consulta,
        private RelogioDoTenant $relogio,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('grelha-horaria.ver');

        $vista = in_array($request->input('vista'), self::VISTAS, true) ? $request->input('vista') : 'turma';
        $data = $this->data($request->input('data'));
        $filtros = $request->only(['turma_id', 'docente_id', 'sala_id']);

        $grelha = match ($vista) {
            'docente' => $request->filled('docente_id') ? $this->consulta->porDocente((int) $request->input('docente_id'), $data) : null,
            'sala' => $request->filled('sala_id') ? $this->consulta->porSala((int) $request->input('sala_id'), $data) : null,
            default => $request->filled('turma_id') ? $this->consulta->porTurma((int) $request->input('turma_id'), $data) : null,
        };

        return Inertia::render('Horario/Grelha/Index', [
            'vista' => $vista,
            'data' => $data->toDateString(),
            'grelha' => $grelha,
            'opcoes' => $this->consulta->opcoes(),
            'filtros' => $filtros,
        ]);
    }

    public function store(GuardarEntradaRequest $request)
    {
        $this->authorize('grelha-horaria.criar');

        $this->service->criar($request->validated());

        return redirect()->back()->with('success', 'Aula adicionada ao horário.');
    }

    public function update(GuardarEntradaRequest $request, GrelhaEntrada $entrada)
    {
        $this->authorize('grelha-horaria.editar');

        $this->service->alterar($entrada, $request->validated());

        return redirect()->back()->with('success', 'Horário actualizado. A versão anterior fica no histórico.');
    }

    public function encerrar(EncerrarEntradaRequest $request, GrelhaEntrada $entrada)
    {
        $this->authorize('grelha-horaria.encerrar');

        $this->service->encerrar($entrada, $request->validated('vigencia_fim'));

        return redirect()->back()->with('success', 'Aula encerrada no horário.');
    }

    public function destroy(GrelhaEntrada $entrada)
    {
        $this->authorize('grelha-horaria.eliminar');

        $this->service->eliminar($entrada);

        return redirect()->back()->with('success', 'Aula removida do horário.');
    }

    private function data(?string $valor): CarbonImmutable
    {
        $valido = $valor !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) === 1 && CarbonImmutable::hasFormat($valor, 'Y-m-d');

        return $valido ? CarbonImmutable::createFromFormat('!Y-m-d', $valor) : $this->relogio->hoje();
    }
}
