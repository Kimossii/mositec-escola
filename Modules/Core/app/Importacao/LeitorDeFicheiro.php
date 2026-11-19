<?php

namespace Modules\Core\Importacao;

use DateTimeInterface;
use Generator;
use RuntimeException;
use Spatie\SimpleExcel\SimpleExcelReader;
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

    /**
     * Lê a primeira folha em fluxo (spatie/simple-excel sobre OpenSpout). A chave é o número da linha no ficheiro (as linhas
     * vazias contam, para os erros apontarem a linha certa). Datas vêm como `aaaa-mm-dd` (ou `aaaa-mm-dd hh:mm:ss` com hora).
     *
     * @return Generator<int, list<?string>>
     */
    private function xlsx(string $caminho): Generator
    {
        $this->recusarFicheiroDescomprimidoEnorme($caminho);

        try {
            $folha = SimpleExcelReader::create($caminho, 'xlsx')->noHeaderRow()->preserveEmptyRows()->getRows();
            $numero = 0;

            foreach ($folha as $celulas) {
                $numero++;
                $linha = array_map(fn ($valor) => $this->valorDaCelula($valor), array_values($celulas));

                // Sem células vazias à direita: a linha acaba na última coluna preenchida.
                while ($linha !== [] && end($linha) === null) {
                    array_pop($linha);
                }

                yield $numero => $linha;
            }
        } catch (\OpenSpout\Common\Exception\IOException|\OpenSpout\Reader\Exception\ReaderException) {
            throw new RuntimeException('O ficheiro Excel está corrompido ou não é um .xlsx.');
        }
    }

    /** Protecção contra «zip bombs»: o tamanho descomprimido de tudo o que o ZIP traz tem de caber no limite. */
    private function recusarFicheiroDescomprimidoEnorme(string $caminho): void
    {
        $zip = new ZipArchive;
        if ($zip->open($caminho) !== true) {
            throw new RuntimeException('O ficheiro Excel está corrompido ou não é um .xlsx.');
        }

        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $total += (int) ($zip->statIndex($i)['size'] ?? 0);
        }
        $zip->close();

        if ($total === 0 || $total > self::MAXIMO_XML_BYTES) {
            throw new RuntimeException('O ficheiro Excel está vazio ou é demasiado grande. Use CSV.');
        }
    }

    private function valorDaCelula(mixed $valor): ?string
    {
        if ($valor instanceof DateTimeInterface) {
            return $valor->format($valor->format('H:i:s') === '00:00:00' ? 'Y-m-d' : 'Y-m-d H:i:s');
        }

        $texto = match (true) {
            $valor === null => null,
            is_bool($valor) => $valor ? '1' : '0',
            // Um número inteiro guardado como decimal (ex.: um BI numérico 123456789.0) vem sem «.0».
            is_float($valor) => $valor === floor($valor) && abs($valor) < 1e15 ? (string) (int) $valor : rtrim(rtrim(sprintf('%.15F', $valor), '0'), '.'),
            default => (string) $valor,
        };

        $texto = $texto === null ? null : trim($texto);

        return $texto === '' ? null : $texto;
    }
}
