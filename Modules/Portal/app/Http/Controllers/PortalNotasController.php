<?php

namespace Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Avaliacao\Services\ExportacaoDePautasService;
use Modules\Avaliacao\Services\NotasDoAlunoService;

/** As notas do educando: a mesma página e os mesmos dados que o aluno vê em «As minhas notas». */
class PortalNotasController extends Controller
{
    public function __construct(
        private NotasDoAlunoService $notas,
        private ExportacaoDePautasService $exportacao,
    ) {
    }

    public function index(Request $request): Response
    {
        $educando = $request->attributes->get('educando');

        return Inertia::render('Avaliacao/MinhasNotas/Index', $this->notas->paraPessoa($educando->dados_pessoa_id) + [
            'educando' => ['id' => $educando->id, 'nome' => $educando->dadosPessoa?->nome_completo],
            'boletim_base' => "/portal/{$educando->id}/notas",
        ]);
    }

    public function boletim(Request $request, int $educando, int $matricula)
    {
        $propria = $this->notas->matriculaDaPessoaComBoletim($request->attributes->get('educando')->dados_pessoa_id, $matricula);

        return $this->exportacao->boletins($propria->turma_id, $propria->id);
    }
}
