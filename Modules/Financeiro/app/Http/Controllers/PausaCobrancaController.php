<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Financeiro\DTO\PausaCobrancaDTO;
use Modules\Financeiro\Http\Requests\AtualizarPausaCobrancaRequest;
use Modules\Financeiro\Http\Requests\CriarPausaCobrancaRequest;
use Modules\Financeiro\Models\PausaCobranca;
use Modules\Financeiro\Services\PausasCobrancaService;

class PausaCobrancaController extends Controller
{
    public function __construct(
        private PausasCobrancaService $service,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('pausa-cobranca.ver');

        return Inertia::render('Financeiro/PausasCobranca/Index', $this->service->pagina($request->filled('ano_lectivo_id') ? (int) $request->input('ano_lectivo_id') : null));
    }

    public function store(CriarPausaCobrancaRequest $request)
    {
        $this->authorize('pausa-cobranca.criar');

        $resumo = $this->service->criar(PausaCobrancaDTO::fromRequest($request), $request->user()?->id);

        return redirect()->back()->with('success', $this->mensagem('Pausa de cobrança criada.', $resumo));
    }

    public function update(AtualizarPausaCobrancaRequest $request, PausaCobranca $pausa)
    {
        $this->authorize('pausa-cobranca.editar');

        $resumo = $this->service->atualizar($pausa, PausaCobrancaDTO::fromRequest($request), $request->user()?->id);

        return redirect()->back()->with('success', $this->mensagem('Pausa de cobrança atualizada.', $resumo));
    }

    public function destroy(PausaCobranca $pausa)
    {
        $this->authorize('pausa-cobranca.eliminar');

        $this->service->eliminar($pausa);

        return redirect()->back()->with('success', 'Pausa de cobrança eliminada.');
    }

    /**
     * @param  array{canceladas: int, mantidas: int}  $resumo
     */
    private function mensagem(string $prefixo, array $resumo): string
    {
        $partes = [$prefixo];

        if ($resumo['canceladas'] > 0) {
            $partes[] = $resumo['canceladas'] . ' propina(s) sem pagamentos cancelada(s) por ficarem isentas.';
        }

        if ($resumo['mantidas'] > 0) {
            $partes[] = $resumo['mantidas'] . ' propina(s) com pagamentos mantida(s): reveja-as manualmente.';
        }

        return implode(' ', $partes);
    }
}
