<?php

namespace Modules\Core\Exportacao;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Ficheiro .xlsx (OpenSpout): título, subtítulo, cabeçalho a verde com filtro e linha fixa, larguras automáticas e números
 * como números. O texto vai sempre como texto (nunca como fórmula), por isso um «=HYPERLINK(…)» numa célula não se executa.
 * O desenho (cores, colunas, o que aparece) é deste ficheiro; o OpenSpout só escreve o formato.
 */
class EscritorXlsx
{
    private const COR_CABECALHO = '166534';

    public function escrever(TabelaExportavel $tabela, string $caminho): void
    {
        $chaves = array_keys($tabela->colunas);
        $larguras = array_map(fn ($rotulo) => mb_strlen($rotulo) + 4, array_values($tabela->colunas));
        $corpo = (new Style())->withFontName('Calibri')->withFontSize(11);
        $cabecalho = $corpo->withFontBold(true)->withFontColor('FFFFFF')->withBackgroundColor(self::COR_CABECALHO);

        $writer = new Writer(new Options(FALLBACK_STYLE: $corpo));
        $writer->openToFile($caminho);

        $folha = $writer->getCurrentSheet();
        $folha->setName($this->nomeDaFolha($tabela->titulo));
        // Título (linha 1), subtítulo (2) e cabeçalho (3): as três ficam fixas ao rolar.
        $folha->setSheetView(new SheetView(freezeRow: 4));

        $writer->addRow(new Row([CelulasSeguras::de($tabela->titulo, $corpo->withFontBold(true)->withFontSize(14))]));
        $writer->addRow(new Row([CelulasSeguras::de(implode(' · ', array_filter([$tabela->entidade, $tabela->subtitulo, 'Gerado em '.date('d/m/Y H:i')])), $corpo->withFontSize(10)->withFontColor('64748B'))]));
        $writer->addRow(new Row(array_map(fn ($rotulo) => CelulasSeguras::de($rotulo, $cabecalho), array_values($tabela->colunas))));

        $total = 0;
        foreach ($tabela->linhas as $dados) {
            $celulas = [];
            foreach ($chaves as $i => $chave) {
                $valor = $dados[$chave] ?? null;
                $celulas[] = CelulasSeguras::de($valor, $corpo);
                $larguras[$i] = max($larguras[$i], min(60, mb_strlen((string) $valor) + 2));
            }
            $writer->addRow(new Row($celulas));
            $total++;
        }

        foreach ($larguras as $i => $largura) {
            $folha->setColumnWidth((float) min(60, $largura), $i + 1);
        }
        $folha->setAutoFilter(new AutoFilter(0, 3, max(count($chaves) - 1, 0), 3 + max($total, 0)));

        $writer->close();
    }

    /** Nome de folha válido para o Excel: sem `[]:*?/\`, até 31 caracteres. */
    private function nomeDaFolha(string $titulo): string
    {
        $limpo = trim(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $titulo) ?? '');

        return mb_substr($limpo !== '' ? $limpo : 'Dados', 0, 31);
    }
}
