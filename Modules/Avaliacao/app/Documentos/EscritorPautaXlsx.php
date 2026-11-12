<?php

namespace Modules\Avaliacao\Documentos;

use RuntimeException;
use ZipArchive;

/**
 * Excel (.xlsx) no formato das pautas oficiais, sem dependências (só a extensão zip): cabeçalho centrado e unido, grupos de
 * colunas com células unidas, bordas, e assinaturas. O texto vai como «inline string», que o Excel nunca interpreta como
 * fórmula. Os números ficam como texto: a pauta imprime-se tal como está, com as casas decimais da escola.
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
        $zip = new ZipArchive;
        if ($zip->open($caminho, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível criar o ficheiro Excel.');
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Pauta" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', $this->estilos());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->folha($doc));
        $zip->close();
    }

    private function estilos(): string
    {
        $centro = '<alignment horizontal="center" vertical="center" wrapText="1"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="3"><font><sz val="10"/><name val="Arial"/></font><font><b/><sz val="10"/><name val="Arial"/></font><font><b/><sz val="13"/><name val="Arial"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"/><right style="thin"/><top style="thin"/><bottom style="thin"/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="6">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1">'.$centro.'</xf>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1">'.$centro.'</xf>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1">'.$centro.'</xf>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1">'.$centro.'</xf>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
            .'</cellXfs></styleSheet>';
    }

    private function folha(DocumentoPauta $doc): string
    {
        $total = $doc->totalDeColunas();
        $linhas = [];
        $unir = [];
        $r = 1;

        $larga = function (string $texto, int $estilo) use (&$linhas, &$unir, &$r, $total): void {
            $linhas[$r] = [[1, $texto, $estilo]];
            $unir[] = 'A'.$r.':'.$this->coluna($total).$r;
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
            $unir[] = $this->coluna($c).$topo.':'.$this->coluna($c).$base;
            $c++;
        }
        foreach ($doc->grupos as $grupo) {
            $n = count($grupo['colunas']);
            for ($k = 0; $k < $n; $k++) {
                $linhas[$topo][] = [$c + $k, $k === 0 ? $grupo['rotulo'] : '', self::ESTILO_CABECALHO];
                $linhas[$base][] = [$c + $k, $grupo['colunas'][$k], self::ESTILO_CABECALHO];
            }
            if ($n > 1) {
                $unir[] = $this->coluna($c).$topo.':'.$this->coluna($c + $n - 1).$topo;
            }
            $c += $n;
        }
        foreach ($doc->finais as $rotulo) {
            $linhas[$topo][] = [$c, $rotulo, self::ESTILO_CABECALHO];
            $linhas[$base][] = [$c, '', self::ESTILO_CABECALHO];
            $unir[] = $this->coluna($c).$topo.':'.$this->coluna($c).$base;
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

        return $this->xml($linhas, $unir, $doc);
    }

    /** @param  array<int, list<array{0: int, 1: string, 2: int}>>  $linhas */
    private function xml(array $linhas, array $unir, DocumentoPauta $doc): string
    {
        ksort($linhas);
        $total = $doc->totalDeColunas();
        $colunas = '';
        foreach (range(1, $total) as $c) {
            $largura = $c - 1 === $doc->indiceDoNome() ? 36 : ($c === 1 ? 5 : 8);
            $colunas .= '<col min="'.$c.'" max="'.$c.'" width="'.$largura.'" customWidth="1"/>';
        }

        $dados = '';
        foreach ($linhas as $numero => $celulas) {
            usort($celulas, fn (array $x, array $y) => $x[0] <=> $y[0]);
            $dados .= '<row r="'.$numero.'">';
            foreach ($celulas as [$coluna, $texto, $estilo]) {
                $ref = $this->coluna($coluna).$numero;
                $dados .= $texto === ''
                    ? '<c r="'.$ref.'" s="'.$estilo.'"/>'
                    : '<c r="'.$ref.'" s="'.$estilo.'" t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars($texto, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
            }
            $dados .= '</row>';
        }

        $merges = $unir === [] ? '' : '<mergeCells count="'.count($unir).'">'.implode('', array_map(fn (string $m) => '<mergeCell ref="'.$m.'"/>', $unir)).'</mergeCells>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><cols>'.$colunas.'</cols><sheetData>'.$dados.'</sheetData>'.$merges
            .'<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';
    }

    /** 1 → A, 27 → AA */
    private function coluna(int $n): string
    {
        $letras = '';
        while ($n > 0) {
            $resto = ($n - 1) % 26;
            $letras = chr(65 + $resto).$letras;
            $n = intdiv($n - 1, 26);
        }

        return $letras;
    }
}
