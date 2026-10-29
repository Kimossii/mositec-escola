<?php

namespace Modules\Core\Importacao;

use Generator;
use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Lê CSV ou XLSX em fluxo (uma linha de cada vez, sem carregar o ficheiro todo) e devolve cada linha como lista de textos.
 * A chave é o número da linha no ficheiro original. Células vazias vêm como null.
 */
class LeitorDeFicheiro
{
    /** Folha de cálculo descomprimida acima disto é recusada (proteção contra «zip bombs»). */
    private const MAXIMO_XML_BYTES = 300 * 1024 * 1024;

    /** @return Generator<int, list<?string>> */
    public function linhas(string $caminho, string $extensao): Generator
    {
        return match (strtolower($extensao)) {
            'xlsx' => $this->xlsx($caminho),
            'csv', 'txt' => $this->csv($caminho),
            default => throw new RuntimeException('Formato não suportado. Use .xlsx ou .csv.'),
        };
    }

    /** @return Generator<int, list<?string>> */
    private function csv(string $caminho): Generator
    {
        $f = fopen($caminho, 'rb') ?: throw new RuntimeException('Não foi possível abrir o ficheiro.');
        $primeira = (string) fgets($f);
        rewind($f);

        if (str_starts_with($primeira, "\xEF\xBB\xBF")) {
            fread($f, 3);
        }

        $separador = collect([';' => substr_count($primeira, ';'), ',' => substr_count($primeira, ','), "\t" => substr_count($primeira, "\t")])->sortDesc()->keys()->first();
        $n = 0;

        while (($linha = fgetcsv($f, 0, $separador, '"', '')) !== false) {
            $n++;
            yield $n => array_map(fn ($c) => $this->texto($c), $linha);
        }

        fclose($f);
    }

    private function texto(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        // Excel em português grava CSV em Windows-1252 quando não se escolhe UTF-8.
        if (! mb_check_encoding($valor, 'UTF-8')) {
            $valor = mb_convert_encoding($valor, 'UTF-8', 'Windows-1252');
        }

        $valor = trim($valor);

        return $valor === '' ? null : $valor;
    }

    /** @return Generator<int, list<?string>> */
    private function xlsx(string $caminho): Generator
    {
        $zip = new ZipArchive;
        if ($zip->open($caminho) !== true) {
            throw new RuntimeException('O ficheiro Excel está corrompido ou não é um .xlsx.');
        }

        $folha = $this->caminhoDaPrimeiraFolha($zip);
        $tamanho = $zip->statName($folha)['size'] ?? 0;
        if ($tamanho === 0 || $tamanho > self::MAXIMO_XML_BYTES) {
            throw new RuntimeException('O ficheiro Excel está vazio ou é demasiado grande. Use CSV.');
        }

        $partilhadas = $this->textosPartilhados($zip);
        $zip->close();

        $xml = new XMLReader;
        $xml->open('zip://'.$caminho.'#'.$folha);

        while ($xml->read()) {
            if ($xml->nodeType !== XMLReader::ELEMENT || $xml->name !== 'row') {
                continue;
            }

            $numero = (int) $xml->getAttribute('r');
            $celulas = [];
            $linhaXml = $xml->readOuterXml();
            $linha = simplexml_load_string($linhaXml);

            foreach ($linha->c ?? [] as $c) {
                $coluna = $this->indiceDaColuna((string) $c['r']);
                $tipo = (string) $c['t'];
                $valor = match ($tipo) {
                    's' => $partilhadas[(int) $c->v] ?? null,
                    'inlineStr' => $this->textoInline($c),
                    default => isset($c->v) ? (string) $c->v : null,
                };
                $valor = $valor === null ? null : trim($valor);
                $celulas[$coluna] = $valor === '' ? null : $valor;
            }

            $maximo = $celulas === [] ? -1 : max(array_keys($celulas));
            $completa = [];
            for ($i = 0; $i <= $maximo; $i++) {
                $completa[] = $celulas[$i] ?? null;
            }

            yield $numero => $completa;
        }

        $xml->close();
    }

    private function caminhoDaPrimeiraFolha(ZipArchive $zip): string
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nome = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $nome) === 1) {
                $folhas[] = $nome;
            }
        }

        if (empty($folhas)) {
            throw new RuntimeException('O ficheiro Excel não tem nenhuma folha.');
        }

        sort($folhas, SORT_NATURAL);

        return $folhas[0];
    }

    /** @return list<string> */
    private function textosPartilhados(ZipArchive $zip): array
    {
        $conteudo = $zip->getFromName('xl/sharedStrings.xml');
        if ($conteudo === false) {
            return [];
        }

        $textos = [];
        $lista = simplexml_load_string($conteudo);
        foreach ($lista->si ?? [] as $si) {
            $texto = isset($si->t) ? (string) $si->t : '';
            foreach ($si->r ?? [] as $parte) {
                $texto .= (string) $parte->t;
            }
            $textos[] = $texto;
        }

        return $textos;
    }

    private function textoInline(\SimpleXMLElement $c): ?string
    {
        if (! isset($c->is)) {
            return null;
        }

        $texto = isset($c->is->t) ? (string) $c->is->t : '';
        foreach ($c->is->r ?? [] as $parte) {
            $texto .= (string) $parte->t;
        }

        return $texto;
    }

    /** «C7» → 2 (A = 0). */
    private function indiceDaColuna(string $referencia): int
    {
        preg_match('/^[A-Z]+/', strtoupper($referencia), $m);
        $indice = 0;
        foreach (str_split($m[0] ?? 'A') as $letra) {
            $indice = $indice * 26 + (ord($letra) - 64);
        }

        return $indice - 1;
    }
}
