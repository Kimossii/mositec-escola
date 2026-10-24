<?php

namespace Modules\Core\Exportacao;

/** CSV em UTF-8 com BOM e `;` (abre bem no Excel em português). Células de texto que um folha de cálculo leria como fórmula levam um apóstrofo. */
class EscritorCsv
{
    public function escrever(TabelaExportavel $tabela, string $caminho): void
    {
        $f = fopen($caminho, 'w');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, array_values($tabela->colunas), ';');

        foreach ($tabela->linhas as $linha) {
            fputcsv($f, array_map(fn ($chave) => self::segura($linha[$chave] ?? null), array_keys($tabela->colunas)), ';');
        }

        fclose($f);
    }

    public static function segura(mixed $valor): string|int
    {
        if ($valor === null) {
            return '';
        }

        if (is_int($valor)) {
            return $valor;
        }

        // Decimais com vírgula e sem milhares: é o que o Excel em português lê como número.
        if (is_float($valor)) {
            return number_format($valor, 2, ',', '');
        }

        $texto = (string) $valor;

        // «+244 923 000 000» é um telefone, não uma fórmula; o resto que comece por = + - @ é neutralizado.
        if (preg_match('/^[=+\-@\t\r]/', $texto) === 1 && preg_match('/^\+[\d\s]+$/', $texto) !== 1) {
            return "'".$texto;
        }

        return $texto;
    }
}
