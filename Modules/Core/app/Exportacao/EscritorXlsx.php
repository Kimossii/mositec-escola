<?php

namespace Modules\Core\Exportacao;

use RuntimeException;
use ZipArchive;

/**
 * Ficheiro .xlsx simples e válido, sem dependências (usa só a extensão zip): título, subtítulo, cabeçalho a verde com
 * filtro e linha fixa, larguras automáticas e números como números. O texto vai como «inline string», que o Excel nunca
 * interpreta como fórmula.
 */
class EscritorXlsx
{
    public function escrever(TabelaExportavel $tabela, string $caminho): void
    {
        $zip = new ZipArchive;
        if ($zip->open($caminho, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível criar o ficheiro Excel.');
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.$this->nomeDaFolha($tabela->titulo).'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="4"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="14"/><name val="Calibri"/></font><font><sz val="10"/><color rgb="FF64748B"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF166534"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs></styleSheet>');

        $zip->addFromString('xl/worksheets/sheet1.xml', $this->folha($tabela));
        $zip->close();
    }

    private function folha(TabelaExportavel $tabela): string
    {
        $chaves = array_keys($tabela->colunas);
        $larguras = array_map(fn ($rotulo) => mb_strlen($rotulo) + 4, array_values($tabela->colunas));
        $linhasXml = [];
        $linha = 1;

        $linhasXml[] = $this->linhaXml($linha++, [[$tabela->titulo, 2]]);
        $linhasXml[] = $this->linhaXml($linha++, [[implode(' · ', array_filter([$tabela->entidade, $tabela->subtitulo, 'Gerado em '.date('d/m/Y H:i')])), 3]]);
        $cabecalho = $linha++;
        $linhasXml[] = $this->linhaXml($cabecalho, array_map(fn ($rotulo) => [$rotulo, 1], array_values($tabela->colunas)));

        foreach ($tabela->linhas as $dados) {
            $celulas = [];
            foreach ($chaves as $i => $chave) {
                $valor = $dados[$chave] ?? null;
                $celulas[] = [$valor, 0];
                $larguras[$i] = max($larguras[$i], min(60, mb_strlen((string) $valor) + 2));
            }
            $linhasXml[] = $this->linhaXml($linha++, $celulas);
        }

        $ultimaColuna = $this->letra(count($chaves));
        $colunas = '';
        foreach ($larguras as $i => $largura) {
            $colunas .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.min(60, $largura).'" customWidth="1"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="'.$cabecalho.'" topLeftCell="A'.($cabecalho + 1).'" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>'.$colunas.'</cols><sheetData>'.implode('', $linhasXml).'</sheetData><autoFilter ref="A'.$cabecalho.':'.$ultimaColuna.max($linha - 1, $cabecalho).'"/></worksheet>';
    }

    /** @param  list<array{0: mixed, 1: int}>  $celulas  [valor, estilo] */
    private function linhaXml(int $linha, array $celulas): string
    {
        $xml = '<row r="'.$linha.'">';
        foreach ($celulas as $i => [$valor, $estilo]) {
            $ref = $this->letra($i + 1).$linha;
            if ($valor === null || $valor === '') {
                $xml .= '<c r="'.$ref.'" s="'.$estilo.'"/>';
            } elseif (is_int($valor) || is_float($valor)) {
                $xml .= '<c r="'.$ref.'" s="'.$estilo.'"><v>'.$valor.'</v></c>';
            } else {
                $xml .= '<c r="'.$ref.'" s="'.$estilo.'" t="inlineStr"><is><t xml:space="preserve">'.$this->xml((string) $valor).'</t></is></c>';
            }
        }

        return $xml.'</row>';
    }

    private function letra(int $coluna): string
    {
        $letra = '';
        while ($coluna > 0) {
            $resto = ($coluna - 1) % 26;
            $letra = chr(65 + $resto).$letra;
            $coluna = intdiv($coluna - 1, 26);
        }

        return $letra;
    }

    /** Remove caracteres de controlo que o XML não admite e escapa o resto. */
    private function xml(string $texto): string
    {
        $texto = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $texto) ?? '';

        return htmlspecialchars($texto, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function nomeDaFolha(string $titulo): string
    {
        $limpo = trim(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $titulo) ?? '');

        return $this->xml(mb_substr($limpo !== '' ? $limpo : 'Dados', 0, 31));
    }
}
