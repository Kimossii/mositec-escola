<?php

namespace Modules\Avaliacao\Documentos;

use Modules\Core\Exportacao\CelulasSeguras;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderName;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\BorderStyle;
use OpenSpout\Common\Entity\Style\BorderWidth;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Options\PageOrientation;
use OpenSpout\Writer\XLSX\Options\PageSetup;
use OpenSpout\Writer\XLSX\Options\PaperSize;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Excel (.xlsx, OpenSpout) no formato das pautas oficiais: cabeçalho centrado e unido, grupos de colunas com células unidas,
 * bordas, assinaturas e página A4 na horizontal ajustada à largura. O texto nunca é fórmula. Os números ficam como texto: a
 * pauta imprime-se tal como está, com as casas decimais da escola. O desenho é deste ficheiro; o OpenSpout só escreve o formato.
 */
class EscritorPautaXlsx
{
    private const ESTILO_NORMAL = 0;

    private const ESTILO_TITULO = 1;

    private const ESTILO_OFICIAL = 2;

    private const ESTILO_CABECALHO = 3;

    private const ESTILO_CELULA = 4;

    private const ESTILO_NOME = 5;

    public function escrever(DocumentoPauta $doc, string $caminho): void
    {
        [$linhas, $unir] = $this->desenhar($doc);

        // A fonte por omissão do livro (Arial 10) define a unidade das larguras de coluna: tem de ser a da pauta, senão a folha fica mais larga.
        $opcoes = new Options(FALLBACK_STYLE: (new Style())->withFontName('Arial')->withFontSize(10), pageSetup: new PageSetup(PageOrientation::LANDSCAPE, PaperSize::A4, fitToHeight: 0, fitToWidth: 1));
        $writer = new Writer($opcoes);
        $writer->openToFile($caminho);

        $folha = $writer->getCurrentSheet();
        $folha->setName('Pauta');
        foreach (range(1, $doc->totalDeColunas()) as $c) {
            $folha->setColumnWidth((float) ($c - 1 === $doc->indiceDoNome() ? 36 : ($c === 1 ? 5 : 8)), $c);
        }

        $estilos = $this->estilos();
        $ultima = $linhas === [] ? 0 : max(array_keys($linhas));
        for ($n = 1; $n <= $ultima; $n++) {
            $writer->addRow(new Row($this->celulasDaLinha($linhas[$n] ?? [], $estilos)));
        }

        // As uniões só se declaram depois de a folha ter linhas.
        foreach ($unir as [$c1, $r1, $c2, $r2]) {
            $opcoes->mergeCells($c1 - 1, $r1, $c2 - 1, $r2, $folha->getIndex());
        }

        $writer->close();
    }

    /**
     * @param  list<array{0: int, 1: string, 2: int}>  $celulas  [coluna (a partir de 1), texto, estilo]
     * @param  array<int, Style>  $estilos
     * @return list<\OpenSpout\Common\Entity\Cell>
     */
    private function celulasDaLinha(array $celulas, array $estilos): array
    {
        $porColuna = [];
        foreach ($celulas as [$coluna, $texto, $estilo]) {
            $porColuna[$coluna] = CelulasSeguras::de($texto, $estilos[$estilo]);
        }

        $saida = [];
        for ($c = 1; $c <= ($porColuna === [] ? 1 : max(array_keys($porColuna))); $c++) {
            $saida[] = $porColuna[$c] ?? new EmptyCell(null);
        }

        return $saida;
    }

    /** @return array<int, Style> */
    private function estilos(): array
    {
        $base = (new Style())->withFontName('Arial')->withFontSize(10);
        $centro = fn (Style $s) => $s->withCellAlignment(CellAlignment::CENTER)->withCellVerticalAlignment(CellVerticalAlignment::CENTER)->withShouldWrapText(true);
        $fina = fn (BorderName $nome) => new BorderPart($nome, Color::BLACK, BorderWidth::THIN, BorderStyle::SOLID);
        $bordas = new Border($fina(BorderName::LEFT), $fina(BorderName::RIGHT), $fina(BorderName::TOP), $fina(BorderName::BOTTOM));

        return [
            self::ESTILO_NORMAL => $base,
            self::ESTILO_TITULO => $centro($base->withFontBold(true)->withFontSize(13)),
            self::ESTILO_OFICIAL => $centro($base->withFontBold(true)),
            self::ESTILO_CABECALHO => $centro($base->withFontBold(true)->withBorder($bordas)),
            self::ESTILO_CELULA => $centro($base->withBorder($bordas)),
            self::ESTILO_NOME => $base->withBorder($bordas)->withCellAlignment(CellAlignment::LEFT)->withCellVerticalAlignment(CellVerticalAlignment::CENTER),
        ];
    }

    /** @return array{0: array<int, list<array{0: int, 1: string, 2: int}>>, 1: list<array{0: int, 1: int, 2: int, 3: int}>} */
    private function desenhar(DocumentoPauta $doc): array
    {
        $total = $doc->totalDeColunas();
        $linhas = [];
        $unir = [];
        $r = 1;

        $larga = function (string $texto, int $estilo) use (&$linhas, &$unir, &$r, $total): void {
            $linhas[$r] = [[1, $texto, $estilo]];
            $unir[] = [1, $r, $total, $r];
            $r++;
        };

        foreach ($doc->linhasCabecalho as $linha) {
            $larga($linha, self::ESTILO_OFICIAL);
        }
        if ($doc->lema) {
            $larga('«'.$doc->lema.'»', self::ESTILO_OFICIAL);
        }
        $larga($doc->titulo, self::ESTILO_TITULO);
        $larga(implode('     ', array_map(fn (array $m) => $m[0].': '.$m[1], $doc->meta)), self::ESTILO_OFICIAL);
        $r++;

        // Cabeçalho da tabela em duas linhas: fixas e finais ocupam as duas; cada grupo une as suas colunas na primeira.
        [$topo, $base] = [$r, $r + 1];
        $c = 1;
        foreach ($doc->fixas as $rotulo) {
            $linhas[$topo][] = [$c, $rotulo, self::ESTILO_CABECALHO];
            $linhas[$base][] = [$c, '', self::ESTILO_CABECALHO];
            $unir[] = [$c, $topo, $c, $base];
            $c++;
        }
        foreach ($doc->grupos as $grupo) {
            $n = count($grupo['colunas']);
            for ($k = 0; $k < $n; $k++) {
                $linhas[$topo][] = [$c + $k, $k === 0 ? $grupo['rotulo'] : '', self::ESTILO_CABECALHO];
                $linhas[$base][] = [$c + $k, $grupo['colunas'][$k], self::ESTILO_CABECALHO];
            }
            if ($n > 1) {
                $unir[] = [$c, $topo, $c + $n - 1, $topo];
            }
            $c += $n;
        }
        foreach ($doc->finais as $rotulo) {
            $linhas[$topo][] = [$c, $rotulo, self::ESTILO_CABECALHO];
            $linhas[$base][] = [$c, '', self::ESTILO_CABECALHO];
            $unir[] = [$c, $topo, $c, $base];
            $c++;
        }
        $r += 2;

        $colunaDoNome = $doc->indiceDoNome();
        foreach ($doc->linhas as $linha) {
            foreach ($linha as $i => $celula) {
                $linhas[$r][] = [$i + 1, $celula, $i === $colunaDoNome ? self::ESTILO_NOME : self::ESTILO_CELULA];
            }
            $r++;
        }

        if ($doc->nota) {
            $r++;
            $larga($doc->nota, self::ESTILO_NORMAL);
        }

        $r += 2;
        foreach ($doc->assinaturas as $a) {
            $linhas[$r][] = [1, str_repeat('_', 34), self::ESTILO_NORMAL];
            $linhas[$r + 1][] = [1, $a['rotulo'], self::ESTILO_OFICIAL];
            if ($a['nome']) {
                $linhas[$r + 2][] = [1, $a['nome'], self::ESTILO_NORMAL];
            }
            $r += 4;
        }

        return [$linhas, $unir];
    }
}
