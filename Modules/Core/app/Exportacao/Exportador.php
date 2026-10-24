<?php

namespace Modules\Core\Exportacao;

use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use ZipArchive;

/**
 * Exporta uma TabelaExportavel em Excel, PDF, CSV ou ZIP (os três ficheiros juntos). Um só sítio para todos os ecrãs que
 * exportam: quem chama escolhe o formato e recebe a resposta de descarga pronta.
 */
class Exportador
{
    /** Acima disto recusa-se a exportação (proteção da memória): peça-se um filtro mais restrito. */
    public const MAXIMO_LINHAS = 50000;

    /** O PDF é pesado; acima disto só se oferecem Excel e CSV (e o ZIP leva só esses dois). */
    public const MAXIMO_LINHAS_PDF = 5000;

    public function responder(TabelaExportavel $tabela, ?string $formato, string $nomeBase): BinaryFileResponse
    {
        $formato = FormatoExportacao::tryFrom((string) $formato)
            ?? throw new HttpException(422, 'Formato de exportação inválido. Use excel, pdf, csv ou zip.');

        $linhas = $tabela->linhasComoLista();
        throw_if(count($linhas) > self::MAXIMO_LINHAS, new HttpException(422, 'Há registos a mais para exportar ('.count($linhas).'). Restrinja os filtros.'));
        throw_if($formato === FormatoExportacao::PDF && count($linhas) > self::MAXIMO_LINHAS_PDF, new HttpException(422, 'Há registos a mais para um PDF. Use Excel ou CSV, ou restrinja os filtros.'));

        $tabela = new TabelaExportavel($tabela->titulo, $tabela->colunas, $linhas, $tabela->subtitulo, $tabela->entidade);
        $nome = Str::slug($nomeBase).'-'.date('Y-m-d').'.'.$formato->extensao();
        $caminho = $this->gerar($tabela, $formato, Str::slug($nomeBase).'-'.date('Y-m-d'), count($linhas));

        return response()->download($caminho, $nome, ['Content-Type' => $formato->mime(), 'X-Content-Type-Options' => 'nosniff'])->deleteFileAfterSend(true);
    }

    private function gerar(TabelaExportavel $tabela, FormatoExportacao $formato, string $base, int $total): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'exp');

        match ($formato) {
            FormatoExportacao::CSV => (new EscritorCsv)->escrever($tabela, $caminho),
            FormatoExportacao::EXCEL => (new EscritorXlsx)->escrever($tabela, $caminho),
            FormatoExportacao::PDF => (new EscritorPdf)->escrever($tabela, $caminho),
            FormatoExportacao::ZIP => $this->empacotar($tabela, $base, $total, $caminho),
        };

        return $caminho;
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
