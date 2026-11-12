<?php

namespace Modules\Avaliacao\Support\Calculo;

/**
 * Quem pode ir ao exame de recurso, por aluno. Tem de ter pelo menos uma disciplina abaixo do mínimo; a escola pode fixar
 * um máximo de disciplinas negativas e disciplinas-chave: passar o máximo, ou ter negativa a uma disciplina-chave, é
 * reprovação directa, sem recurso. Pura: recebe as notas finais antes do recurso, não toca na base de dados.
 */
class RegraDoRecurso
{
    public const MOTIVO_MAXIMO = 'maximo';

    public const MOTIVO_CHAVE = 'chave';

    /**
     * @param  array<int, ?string>  $notasBase  disciplina => nota final arredondada antes do recurso (`null` = ainda sem nota)
     * @param  list<int>  $chave  disciplinas-chave
     * @return array{reprovadas: list<int>, elegivel: bool, motivo: ?string, chave: list<int>}
     */
    public function avaliar(array $notasBase, string $minima, ?int $maximo, array $chave, CalculadoraQuantitativa $calculadora): array
    {
        $reprovadas = [];
        foreach ($notasBase as $disciplinaId => $nota) {
            if ($nota !== null && ! $calculadora->aprovadoNaDisciplina($nota, $minima)) {
                $reprovadas[] = (int) $disciplinaId;
            }
        }

        $chaveReprovadas = array_values(array_intersect($reprovadas, $chave));
        $motivo = match (true) {
            $chaveReprovadas !== [] => self::MOTIVO_CHAVE,
            $maximo !== null && count($reprovadas) > $maximo => self::MOTIVO_MAXIMO,
            default => null,
        };

        return ['reprovadas' => $reprovadas, 'elegivel' => $reprovadas !== [] && $motivo === null, 'motivo' => $motivo, 'chave' => $chaveReprovadas];
    }

    /** A frase para o ecrã. @param array<int, string> $nomes disciplina => nome */
    public static function descrever(array $avaliacao, ?int $maximo, array $nomes): ?string
    {
        return match ($avaliacao['motivo']) {
            self::MOTIVO_CHAVE => 'Negativa a disciplina-chave ('.implode(', ', array_map(fn (int $id) => $nomes[$id] ?? '?', $avaliacao['chave'])).'): reprovação directa, sem recurso.',
            self::MOTIVO_MAXIMO => 'Tem '.count($avaliacao['reprovadas'])." disciplinas negativas (o máximo para recurso é {$maximo}): reprovação directa, sem recurso.",
            default => null,
        };
    }
}
