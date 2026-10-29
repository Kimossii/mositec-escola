<?php

namespace Modules\Core\Http\Controllers\Ficheiros;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Modules\Core\Exportacao\FormatoExportacao;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\PartilhaExportacao;
use Modules\Core\Models\PedidoExportacao;
use Modules\Usuario\Models\User;
use Modules\Core\Traits\LogService;
use Illuminate\Support\Str;
use ZipArchive;

/** «Os meus ficheiros»: as exportações pesadas do próprio utilizador. Cada um só vê e descarrega as suas. */
class FicheiroController extends Controller
{
    use LogService;

    public function index(Request $request)
    {
        $eu = $request->user()->id;
        $meus = PedidoExportacao::query()->where('user_id', $eu);
        $pedidos = (clone $meus)->with('partilhas.destinatario:id,name')->latest('id')->limit(200)->get();
        $recebidos = PartilhaExportacao::query()->where('destinatario_id', $eu)->with(['pedido', 'partilhadoPor:id,name'])->latest('id')->limit(200)->get()
            ->filter(fn (PartilhaExportacao $p) => $p->pedido?->estado === PedidoExportacao::PRONTO);

        // Ao abrir a página o dono vê os resultados novos: o ponto do sino apaga-se.
        $meus->whereNull('visto_em')->where('estado', '!=', PedidoExportacao::PENDENTE)->update(['visto_em' => now()]);
        PartilhaExportacao::query()->where('destinatario_id', $eu)->whereNull('visto_em')->update(['visto_em' => now()]);

        return Inertia::render('Core/Ficheiros/Index', [
            'pedidos' => $pedidos->map(fn (PedidoExportacao $p) => $this->linha($p) + [
                'partilhavel' => $p->permissao !== null && $p->estado === PedidoExportacao::PRONTO,
                'partilhado_com' => $p->partilhas->map(fn ($x) => ['id' => $x->destinatario_id, 'nome' => $x->destinatario?->name])->values(),
            ]),
            'partilhados' => $recebidos->map(fn (PartilhaExportacao $p) => $this->linha($p->pedido) + ['partilhado_por' => $p->partilhadoPor?->name])->values(),
        ]);
    }

    /** @return array<string, mixed> */
    private function linha(PedidoExportacao $p): array
    {
        return [
            'id' => $p->id, 'titulo' => $p->titulo, 'formato' => $p->formato, 'nome' => $p->nome, 'linhas' => $p->linhas, 'tamanho' => $p->tamanho,
            'estado' => $p->estado, 'erro' => $p->erro, 'criado_em' => $p->created_at?->toIso8601String(), 'concluido_em' => $p->concluido_em?->toIso8601String(),
        ];
    }

    /** Colegas a quem este ficheiro pode ser partilhado (têm a permissão do tipo de ficheiro), por pesquisa de nome ou e-mail. */
    public function destinatarios(Request $request, PedidoExportacao $pedido)
    {
        abort_unless($pedido->user_id === $request->user()->id && $pedido->permissao !== null, 404);
        $q = trim((string) $request->query('q', ''));

        $utilizadores = User::query()->where('id', '!=', $request->user()->id)
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")->orWhere('email', 'ilike', "%{$q}%")))
            ->orderBy('name')->limit(40)->get(['id', 'name', 'email'])
            ->filter(fn (User $u) => $this->podeReceber($u, $pedido))
            ->map(fn (User $u) => ['id' => $u->id, 'nome' => $u->name, 'email' => $u->email])->values();

        return response()->json($utilizadores);
    }

    /**
     * Define exactamente com quem cada ficheiro (prontos e do próprio) fica partilhado: acrescenta os novos e retira os que saíram.
     * Só colegas da escola com a permissão do tipo de ficheiro; nunca o próprio. Só leitura.
     */
    public function partilhar(Request $request)
    {
        $dados = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['integer'],
            'destinatarios' => ['present', 'array', 'max:50'], 'destinatarios.*' => ['integer'],
        ]);

        $pedidos = PedidoExportacao::query()->where('user_id', $request->user()->id)->whereIn('id', $dados['ids'])
            ->where('estado', PedidoExportacao::PRONTO)->whereNotNull('permissao')->get();
        abort_if($pedidos->isEmpty(), 404);

        $utilizadores = User::query()->whereIn('id', $dados['destinatarios'])->where('id', '!=', $request->user()->id)->get();

        foreach ($pedidos as $pedido) {
            foreach ($utilizadores as $u) {
                if (! $this->podeReceber($u, $pedido)) {
                    throw ValidationException::withMessages(['destinatarios' => "{$u->name} não tem permissão para receber «{$pedido->titulo}»."]);
                }
            }

            PartilhaExportacao::query()->where('pedido_exportacao_id', $pedido->id)->whereNotIn('destinatario_id', $utilizadores->pluck('id'))->delete();
            foreach ($utilizadores as $u) {
                PartilhaExportacao::query()->firstOrCreate(['pedido_exportacao_id' => $pedido->id, 'destinatario_id' => $u->id], ['partilhado_por' => $request->user()->id]);
            }
        }

        $this->logInfo('Ficheiros de exportação partilhados', ['ficheiros' => $pedidos->count(), 'destinatarios' => $utilizadores->count()]);

        return redirect()->back()->with('success', $utilizadores->isEmpty() ? 'Partilha removida.' : 'Partilhado com '.$utilizadores->count().' colega(s).');
    }

    /** O destinatário tem TODAS as permissões do tipo de ficheiro (verificadas também no momento da descarga). */
    private function podeReceber(User $utilizador, PedidoExportacao $pedido): bool
    {
        foreach (array_filter(explode(',', (string) $pedido->permissao)) as $permissao) {
            if (! $utilizador->can(trim($permissao))) {
                return false;
            }
        }

        return $pedido->permissao !== null;
    }

    /** Elimina os ficheiros do próprio utilizador que já terminaram (prontos ou falhados); os «a preparar» não se tocam. */
    public function eliminar(Request $request)
    {
        $dados = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['integer']]);

        $pedidos = PedidoExportacao::query()->where('user_id', $request->user()->id)->whereIn('id', $dados['ids'])->where('estado', '!=', PedidoExportacao::PENDENTE)->get();

        foreach ($pedidos as $pedido) {
            if ($pedido->caminho) {
                Storage::disk('local')->delete($pedido->caminho);
            }
            $pedido->delete();
        }

        $this->logInfo('Ficheiros de exportação eliminados', ['total' => $pedidos->count()]);

        return redirect()->back()->with('success', $pedidos->count() === 1 ? 'Ficheiro eliminado.' : "{$pedidos->count()} ficheiros eliminados.");
    }

    /** ZIP com todos os ficheiros prontos de uma pasta (tipo de exportação) do próprio utilizador. */
    public function descarregarPasta(Request $request)
    {
        $titulo = $request->validate(['titulo' => ['required', 'string', 'max:255']])['titulo'];
        $pedidos = PedidoExportacao::query()->where('user_id', $request->user()->id)->where('titulo', $titulo)->where('estado', PedidoExportacao::PRONTO)->get();
        abort_if($pedidos->isEmpty(), 404, 'Esta pasta não tem ficheiros prontos.');

        $disco = Storage::disk('local');
        $caminho = tempnam(sys_get_temp_dir(), 'pasta');
        $zip = new ZipArchive;
        $zip->open($caminho, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $usados = [];
        foreach ($pedidos as $pedido) {
            if ($pedido->caminho && $disco->exists($pedido->caminho)) {
                // Nomes iguais (mesmo dia): acrescenta o número do pedido.
                $nome = isset($usados[$pedido->nome]) ? "{$pedido->id}-{$pedido->nome}" : $pedido->nome;
                $usados[$pedido->nome] = true;
                $zip->addFile($disco->path($pedido->caminho), $nome);
            }
        }
        $zip->close();

        $this->logInfo('Pasta de exportações descarregada', ['pasta' => $titulo, 'ficheiros' => $pedidos->count()]);

        return response()->download($caminho, Str::slug($titulo).'.zip', ['Content-Type' => 'application/zip', 'X-Content-Type-Options' => 'nosniff'])->deleteFileAfterSend(true);
    }

    public function descarregar(Request $request, PedidoExportacao $pedido)
    {
        $partilhado = $pedido->user_id !== $request->user()->id
            && PartilhaExportacao::query()->where('pedido_exportacao_id', $pedido->id)->where('destinatario_id', $request->user()->id)->exists()
            && $this->podeReceber($request->user(), $pedido);
        abort_unless(($pedido->user_id === $request->user()->id || $partilhado) && $pedido->estado === PedidoExportacao::PRONTO, 404);

        $disco = Storage::disk('local');
        abort_unless($pedido->caminho && $disco->exists($pedido->caminho), 404, 'O ficheiro já não está disponível. Peça a exportação de novo.');

        $this->logInfo('Exportação descarregada', ['pedido' => $pedido->id, 'partilhado' => $partilhado, 'formato' => $pedido->formato, 'linhas' => $pedido->linhas]);

        return $disco->download($pedido->caminho, $pedido->nome, ['Content-Type' => FormatoExportacao::from($pedido->formato)->mime(), 'X-Content-Type-Options' => 'nosniff']);
    }
}
