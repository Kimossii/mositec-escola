<?php

namespace Modules\Core\Exportacao;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Style\Style;

/**
 * Células de Excel que nunca são fórmulas. O `Cell::fromValue()` do OpenSpout trata um texto que começa por «=» como
 * fórmula (um «=HYPERLINK(...)» vindo de um nome de aluno seria executado ao abrir o ficheiro); aqui o texto é sempre texto.
 */
final class CelulasSeguras
{
    public static function de(mixed $valor, ?Style $estilo = null): Cell
    {
        return match (true) {
            $valor === null || $valor === '' => new EmptyCell(null, $estilo),
            is_int($valor) || is_float($valor) => new NumericCell($valor, $estilo),
            default => new StringCell((string) $valor, $estilo),
        };
    }
}
