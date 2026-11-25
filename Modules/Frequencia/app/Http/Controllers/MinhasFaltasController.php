<?php

namespace Modules\Frequencia\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Frequencia\Services\ConsultaFrequenciaService;

/** «As minhas faltas»: o aluno vê só as suas (a conta resolve a matrícula pela pessoa). */
class MinhasFaltasController extends Controller
{
    public function __construct(private ConsultaFrequenciaService $consulta)
    {
    }

    public function index(Request $request)
    {
        $this->authorize('frequencia.ver-proprias');

        return Inertia::render('Frequencia/MinhasFaltas', ['blocos' => $this->consulta->minhasFaltas($request->user())]);
    }
}
