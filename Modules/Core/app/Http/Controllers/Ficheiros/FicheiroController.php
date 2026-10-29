<?php

namespace Modules\Core\Http\Controllers\Ficheiros;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Modules\Core\Exportacao\FormatoExportacao;
use Modules\Core\Models\PedidoExportacao;
use Modules\Core\Traits\LogService;

/** «Os meus ficheiros»: as exportações pesadas do próprio utilizador. Cada um só vê e descarrega as suas. */
class FicheiroController extends Controller
{
    use LogService;

    public function index(Request $request)
    {
        $meus = PedidoExportacao::query()->where('user_id', $request->user()->id);
        $pedidos = (clone $meus)->latest('id')->limit(30)->get();
        // Ao abrir a página o dono vê os resultados novos: o ponto do sino apaga-se.
        $meus->whereNull('visto_em')->where('estado', '!=', PedidoExportacao::PENDENTE)->update(['visto_em' => now()]);
        $pedidos = $pedidos
            ->map(fn (PedidoExportacao $p) => [
                'id' => $p->id, 'titulo' => $p->titulo, 'formato' => $p->formato, 'nome' => $p->nome, 'linhas' => $p->linhas, 'tamanho' => $p->tamanho,
                'estado' => $p->estado, 'erro' => $p->erro, 'criado_em' => $p->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Core/Ficheiros/Index', ['pedidos' => $pedidos]);
    }

    public function descarregar(Request $request, PedidoExportacao $pedido)
    {
        abort_unless($pedido->user_id === $request->user()->id && $pedido->estado === PedidoExportacao::PRONTO, 404);

        $disco = Storage::disk('local');
        abort_unless($pedido->caminho && $disco->exists($pedido->caminho), 404, 'O ficheiro já não está disponível. Peça a exportação de novo.');

        $this->logInfo('Exportação descarregada', ['pedido' => $pedido->id, 'formato' => $pedido->formato, 'linhas' => $pedido->linhas]);

        return $disco->download($pedido->caminho, $pedido->nome, ['Content-Type' => FormatoExportacao::from($pedido->formato)->mime(), 'X-Content-Type-Options' => 'nosniff']);
    }
}
