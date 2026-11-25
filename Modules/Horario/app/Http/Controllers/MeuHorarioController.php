<?php

namespace Modules\Horario\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Horario\Services\ConsultaGrelhaService;

/** "O meu horário" (Área do Professor): só as aulas das turmas e disciplinas que o utilizador lecciona na data. */
class MeuHorarioController extends Controller
{
    public function __construct(
        private ConsultaGrelhaService $consulta,
        private RelogioDoTenant $relogio,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('area-professor.ver');

        $valor = (string) $request->input('data');
        $valido = preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) === 1 && CarbonImmutable::hasFormat($valor, 'Y-m-d');
        $data = $valido ? CarbonImmutable::createFromFormat('!Y-m-d', $valor) : $this->relogio->hoje();

        return Inertia::render('Horario/MeuHorario', [
            'data' => $data->toDateString(),
            'grelha' => $this->consulta->doUtilizador($request->user()->id, $data),
        ]);
    }
}
