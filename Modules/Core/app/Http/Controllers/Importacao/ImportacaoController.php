<?php

namespace Modules\Core\Http\Controllers\Importacao;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Modules\Core\Exportacao\Exportador;
use Modules\Core\Importacao\ImportadorDeEntidade;
use Modules\Core\Importacao\RegistoDeImportadores;
use Modules\Core\Importacao\ServicoDeImportacao;
use Modules\Core\Models\Importacao;
use Modules\Core\Models\ImportacaoLinha;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Importações em massa: modelo, carregar, acompanhar, confirmar e ficheiro de erros. Cada utilizador só vê as suas. */
class ImportacaoController extends Controller
{
    public function __construct(private RegistoDeImportadores $registo, private ServicoDeImportacao $servico)
    {
    }

    public function index(Request $request)
    {
        $importacoes = Importacao::query()->where('user_id', $request->user()->id)->latest('id')->limit(30)->get()
            ->map(fn (Importacao $i) => $this->resumo($i));

        return Inertia::render('Core/Importacoes/Index', ['importacoes' => $importacoes]);
    }

    public function nova(string $entidade)
    {
        $importador = $this->importador($entidade);

        return Inertia::render('Core/Importacoes/Nova', [
            'entidade' => $importador->chave(),
            'titulo' => $importador->titulo(),
            'colunas' => array_values(array_map(fn ($c, $k) => ['chave' => $k] + $c, $importador->colunas(), array_keys($importador->colunas()))),
        ]);
    }

    public function modelo(Request $request, string $entidade, Exportador $exportador)
    {
        $importador = $this->importador($entidade);

        return $exportador->responder($this->servico->modelo($importador), $request->query('formato', 'excel'), 'modelo-importacao-'.$importador->chave());
    }

    public function store(Request $request, string $entidade)
    {
        $importador = $this->importador($entidade);
        $request->validate(['ficheiro' => ['required', 'file', 'extensions:xlsx,csv,txt', 'max:'.(ServicoDeImportacao::MAXIMO_BYTES / 1024)]], [
            'ficheiro.required' => 'Escolha o ficheiro preenchido.',
            'ficheiro.extensions' => 'Use um ficheiro .xlsx ou .csv.',
            'ficheiro.max' => 'O ficheiro não pode ter mais de 50 MB.',
        ]);

        Gate::authorize($importador->permissao());
        $importacao = $this->servico->iniciar($request->user(), $importador, $request->file('ficheiro'));

        return redirect()->route('importacoes.show', $importacao);
    }

    public function show(Request $request, Importacao $importacao)
    {
        $this->daqui($request, $importacao);

        $erros = ImportacaoLinha::where('importacao_id', $importacao->id)->whereIn('estado', [ImportacaoLinha::ERRO, ImportacaoLinha::IGNORADA])
            ->orderBy('linha')->limit(50)->get(['linha', 'estado', 'erros'])
            ->map(fn ($l) => ['linha' => $l->linha, 'ignorada' => $l->estado === ImportacaoLinha::IGNORADA, 'erros' => $l->erros]);

        return Inertia::render('Core/Importacoes/Show', ['importacao' => $this->resumo($importacao), 'amostra' => $erros]);
    }

    public function confirmar(Request $request, Importacao $importacao)
    {
        $this->daqui($request, $importacao);
        Gate::authorize($this->importador($importacao->entidade)->permissao());
        $this->servico->confirmar($importacao);

        return redirect()->route('importacoes.show', $importacao);
    }

    public function cancelar(Request $request, Importacao $importacao)
    {
        $this->daqui($request, $importacao);
        Gate::authorize($this->importador($importacao->entidade)->permissao());
        $this->servico->cancelar($importacao);

        return redirect()->route('importacoes.show', $importacao);
    }

    /** CSV com as linhas com erro (e as ignoradas), com o motivo, para corrigir e voltar a carregar. */
    public function erros(Request $request, Importacao $importacao): StreamedResponse
    {
        $this->daqui($request, $importacao);
        $importador = $this->importador($importacao->entidade);
        $chaves = array_keys($importador->colunas());

        return response()->streamDownload(function () use ($importacao, $importador, $chaves) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, ['Linha', ...array_map(fn ($c) => $importador->colunas()[$c]['rotulo'], $chaves), 'Problema'], ';');

            ImportacaoLinha::where('importacao_id', $importacao->id)->whereIn('estado', [ImportacaoLinha::ERRO, ImportacaoLinha::IGNORADA])->orderBy('linha')
                ->chunkById(1000, function ($linhas) use ($f, $chaves) {
                    foreach ($linhas as $l) {
                        fputcsv($f, [$l->linha, ...array_map(fn ($c) => \Modules\Core\Exportacao\EscritorCsv::segura($l->dados[$c] ?? null), $chaves), implode(' | ', $l->erros ?? [])], ';');
                    }
                });

            fclose($f);
        }, 'erros-importacao-'.$importacao->id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function importador(string $entidade): ImportadorDeEntidade
    {
        abort_unless($this->registo->existe($entidade), 404);
        $importador = $this->registo->obter($entidade);
        Gate::authorize($importador->permissao());

        return $importador;
    }

    /** Só o dono, e só enquanto continua a ter a permissão da entidade. */
    private function daqui(Request $request, Importacao $importacao): void
    {
        abort_unless($importacao->user_id === $request->user()->id, 404);
        $this->importador($importacao->entidade);
    }

    private function resumo(Importacao $i): array
    {
        $importador = $this->registo->existe($i->entidade) ? $this->registo->obter($i->entidade) : null;

        return [
            'id' => $i->id, 'entidade' => $i->entidade, 'titulo' => $importador?->titulo() ?? $i->entidade, 'nome_ficheiro' => $i->nome_ficheiro,
            'estado' => $i->estado, 'estado_texto' => Importacao::ESTADOS[$i->estado] ?? '', 'em_curso' => $i->emCurso(),
            'total' => $i->total, 'validas' => $i->validas, 'ignoradas' => $i->ignoradas, 'com_erro' => $i->com_erro, 'importadas' => $i->importadas,
            'erro_geral' => $i->erro_geral, 'criado_em' => $i->created_at?->toIso8601String(),
        ];
    }
}
