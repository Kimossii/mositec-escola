<?php

namespace Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Frequencia\Services\ConsultaFrequenciaService;

/** As faltas do educando: a mesma página e os mesmos dados que o aluno vê em «As minhas faltas». */
class PortalFaltasController extends Controller
{
    public function __construct(private ConsultaFrequenciaService $consulta)
    {
    }

    public function index(Request $request): Response
    {
        $educando = $request->attributes->get('educando');

        return Inertia::render('Frequencia/MinhasFaltas', [
            'blocos' => $this->consulta->faltasDaPessoa($educando->dados_pessoa_id),
            'educando' => ['id' => $educando->id, 'nome' => $educando->dadosPessoa?->nome_completo],
        ]);
    }
}
