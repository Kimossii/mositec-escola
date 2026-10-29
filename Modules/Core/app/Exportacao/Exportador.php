<?php

namespace Modules\Core\Exportacao;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Core\Models\PedidoExportacao;
use Modules\Core\Tenancy\TenantContext;
use Modules\Core\Traits\LogService;
use Throwable;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use ZipArchive;

/**
 * Exporta uma TabelaExportavel em Excel, PDF, CSV ou ZIP (os três ficheiros juntos). Um só sítio para todos os ecrãs que
 * exportam: quem chama escolhe o formato e recebe a resposta de descarga pronta.
 */
class Exportador
{
    use LogService;

    /** Acima disto recusa-se a exportação (proteção da memória): peça-se um filtro mais restrito. */
    public const MAXIMO_LINHAS = 50000;

    /** O PDF é pesado; acima disto só se oferecem Excel e CSV (e o ZIP leva só esses dois). */
    public const MAXIMO_LINHAS_PDF = 5000;

    /** Até estas linhas a descarga é imediata; acima, com uma fila a sério (não `sync`), gera-se em fila e o utilizador recebe-o em «Os meus ficheiros». */
    public const LINHAS_IMEDIATAS = ['csv' => 5000, 'excel' => 5000, 'pdf' => 300, 'zip' => 300];

    /** Descarga imediata; ou 202 com o pedido, se foi para a fila. */
    public function responder(TabelaExportavel $tabela, ?string $formato, string $nomeBase): BinaryFileResponse|JsonResponse
    {
        $formato = FormatoExportacao::tryFrom((string) $formato)
            ?? throw new HttpException(422, 'Formato de exportação inválido. Use excel, pdf, csv ou zip.');

        $linhas = $tabela->linhasComoLista();
        throw_if(count($linhas) > self::MAXIMO_LINHAS, new HttpException(422, 'Há registos a mais para exportar ('.count($linhas).'). Restrinja os filtros.'));
        throw_if($formato === FormatoExportacao::PDF && count($linhas) > self::MAXIMO_LINHAS_PDF, new HttpException(422, 'Há registos a mais para um PDF. Use Excel ou CSV, ou restrinja os filtros.'));

        $tabela = new TabelaExportavel($tabela->titulo, $tabela->colunas, $linhas, $tabela->subtitulo, $tabela->entidade, $tabela->assinaturas);
        $nome = Str::slug($nomeBase).'-'.date('Y-m-d').'.'.$formato->extensao();

        if ($this->deveEnfileirar($formato, count($linhas))) {
            return $this->enfileirar($tabela, $formato, $nome);
        }

        $contexto = ['exportacao' => $tabela->titulo, 'formato' => $formato->value, 'linhas' => count($linhas)];

        try {
            $caminho = $this->gerar($tabela, $formato, Str::slug($nomeBase).'-'.date('Y-m-d'), count($linhas));
        } catch (Throwable $excecao) {
            // Falha ao gerar o ficheiro (disco cheio, PDF que não renderiza…): regista o detalhe e devolve uma mensagem limpa ao utilizador.
            $this->logException($excecao, 'Exportação falhou', $contexto);

            throw new HttpException(500, 'Não foi possível gerar o ficheiro. Tente novamente.', $excecao);
        }

        $this->logInfo('Exportação gerada', $contexto);

        return response()->download($caminho, $nome, ['Content-Type' => $formato->mime(), 'X-Content-Type-Options' => 'nosniff'])->deleteFileAfterSend(true);
    }

    /** Escreve o ficheiro num temporário e devolve o caminho (quem chama apaga-o). */
    public function gerarFicheiro(TabelaExportavel $tabela, FormatoExportacao $formato, string $base): string
    {
        $linhas = $tabela->linhasComoLista();

        return $this->gerar(new TabelaExportavel($tabela->titulo, $tabela->colunas, $linhas, $tabela->subtitulo, $tabela->entidade, $tabela->assinaturas), $formato, $base, count($linhas));
    }

    private function deveEnfileirar(FormatoExportacao $formato, int $total): bool
    {
        return config('queue.default') !== 'sync'
            && auth()->check()
            && app(TenantContext::class)->temTenant()
            && $total > (config('exportacao.linhas_imediatas', self::LINHAS_IMEDIATAS)[$formato->value] ?? self::LINHAS_IMEDIATAS[$formato->value]);
    }

    /** A tabela vai para um ficheiro temporário (não para o payload do job) e o job gera o ficheiro final. */
    private function enfileirar(TabelaExportavel $tabela, FormatoExportacao $formato, string $nome): JsonResponse
    {
        $pendentes = PedidoExportacao::query()->where('user_id', auth()->id())->where('estado', PedidoExportacao::PENDENTE);

        // Duplo clique: reaproveita o pedido igual que ainda está a preparar.
        $igual = (clone $pendentes)->where('titulo', $tabela->titulo)->where('formato', $formato->value)->first();

        if ($igual !== null) {
            return $this->respostaDeFila($igual);
        }

        throw_if(
            $pendentes->count() >= PedidoExportacao::MAXIMO_PENDENTES,
            new HttpException(429, 'Já tem '.PedidoExportacao::MAXIMO_PENDENTES.' exportações a preparar. Aguarde que terminem e tente de novo.'),
        );

        $pedido = PedidoExportacao::create([
            'user_id' => auth()->id(),
            'titulo' => $tabela->titulo,
            'formato' => $formato->value,
            'nome' => $nome,
            'linhas' => count($tabela->linhasComoLista()),
            'estado' => PedidoExportacao::PENDENTE,
        ]);

        Storage::disk('local')->put(GerarExportacaoJob::origem($pedido->id), json_encode([
            'titulo' => $tabela->titulo, 'colunas' => $tabela->colunas, 'linhas' => $tabela->linhasComoLista(),
            'subtitulo' => $tabela->subtitulo, 'entidade' => $tabela->entidade, 'assinaturas' => $tabela->assinaturas,
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));

        dispatch(new GerarExportacaoJob($pedido->id));
        $this->logInfo('Exportação enfileirada', ['pedido' => $pedido->id, 'formato' => $formato->value, 'linhas' => $pedido->linhas]);

        return $this->respostaDeFila($pedido);
    }

    private function respostaDeFila(PedidoExportacao $pedido): JsonResponse
    {
        return response()->json(['pedido_id' => $pedido->id, 'mensagem' => 'A exportação é grande e está a ser preparada. Quando estiver pronta, encontra-a em «Os meus ficheiros».'], 202);
    }

    private function gerar(TabelaExportavel $tabela, FormatoExportacao $formato, string $base, int $total): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'exp');

        try {
            $this->escreverFormato($tabela, $formato, $base, $total, $caminho);
        } catch (Throwable $excecao) {
            @unlink($caminho);

            throw $excecao;
        }

        return $caminho;
    }

    private function escreverFormato(TabelaExportavel $tabela, FormatoExportacao $formato, string $base, int $total, string $caminho): void
    {
        match ($formato) {
            FormatoExportacao::CSV => (new EscritorCsv)->escrever($tabela, $caminho),
            FormatoExportacao::EXCEL => (new EscritorXlsx)->escrever($tabela, $caminho),
            FormatoExportacao::PDF => (new EscritorPdf)->escrever($tabela, $caminho),
            FormatoExportacao::ZIP => $this->empacotar($tabela, $base, $total, $caminho),
        };
    }

    private function empacotar(TabelaExportavel $tabela, string $base, int $total, string $destino): void
    {
        $partes = [
            "{$base}.xlsx" => fn (string $f) => (new EscritorXlsx)->escrever($tabela, $f),
            "{$base}.csv" => fn (string $f) => (new EscritorCsv)->escrever($tabela, $f),
        ];
        if ($total <= self::MAXIMO_LINHAS_PDF) {
            $partes["{$base}.pdf"] = fn (string $f) => (new EscritorPdf)->escrever($tabela, $f);
        }

        $zip = new ZipArchive;
        $zip->open($destino, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $temporarios = [];
        foreach ($partes as $nome => $escrever) {
            $f = tempnam(sys_get_temp_dir(), 'exp');
            $escrever($f);
            $zip->addFile($f, $nome);
            $temporarios[] = $f;
        }
        $zip->close();

        foreach ($temporarios as $f) {
            @unlink($f);
        }
    }
}
