<?php

namespace Modules\Financeiro\DTO;

/**
 * Resultado de uma geração (ou pré-visualização): totais de todas as matrículas e o detalhe por matrícula,
 * limitado (D13) com as matrículas com erros e conflitos primeiro, para o recorte nunca esconder problemas.
 */
final class ResultadoGeracaoPropinasDTO
{
    public const LIMITE_DETALHE = 200;

    /** @var list<ResultadoGeracaoMatriculaDTO> */
    private array $matriculas = [];

    /** @var bool a pré-visualização inclui alguma multa estimada (indicação para várias matrículas). */
    private bool $incluiMultasEstimadas = false;

    public function __construct(public readonly bool $previsualizacao)
    {
    }

    public function juntar(ResultadoGeracaoMatriculaDTO $matricula): void
    {
        $this->matriculas[] = $matricula;
    }

    public function comIncluiMultasEstimadas(bool $inclui): void
    {
        $this->incluiMultasEstimadas = $inclui;
    }

    /**
     * @return list<ResultadoGeracaoMatriculaDTO>
     */
    public function matriculas(): array
    {
        return $this->matriculas;
    }

    /**
     * @return array{matriculas: int, criadas: int, ignoradas: int, conflitos: int, sem_plano: int, erros: int, capital: int, multas: int}
     */
    public function totais(): array
    {
        $totais = ['matriculas' => count($this->matriculas), 'criadas' => 0, 'ignoradas' => 0, 'conflitos' => 0, 'sem_plano' => 0, 'erros' => 0, 'capital' => 0, 'multas' => 0];

        foreach ($this->matriculas as $matricula) {
            foreach ($matricula->contagens() as $chave => $quantidade) {
                $totais[$chave] += $quantidade;
            }

            $totais['capital'] += $matricula->totalCapital();
            $totais['multas'] += $matricula->totalMulta() ?? 0;
        }

        return $totais;
    }

    /**
     * @return array{previsualizacao: bool, totais: array<string, int>, matriculas: list<array<string, mixed>>, detalhe_truncado: bool, inclui_multas_estimadas: bool}
     */
    public function toArray(int $limite = self::LIMITE_DETALHE): array
    {
        $ordenadas = $this->matriculas;

        usort($ordenadas, function (ResultadoGeracaoMatriculaDTO $a, ResultadoGeracaoMatriculaDTO $b): int {
            $ca = $a->contagens();
            $cb = $b->contagens();

            return [$cb['erros'] > 0, $cb['conflitos'] > 0, $cb['criadas'] > 0, $a->matricula_id]
                <=> [$ca['erros'] > 0, $ca['conflitos'] > 0, $ca['criadas'] > 0, $b->matricula_id];
        });

        return [
            'previsualizacao' => $this->previsualizacao,
            'totais' => $this->totais(),
            'matriculas' => array_map(fn (ResultadoGeracaoMatriculaDTO $m) => $m->toArray(), array_slice($ordenadas, 0, $limite)),
            'detalhe_truncado' => count($ordenadas) > $limite,
            'inclui_multas_estimadas' => $this->incluiMultasEstimadas,
        ];
    }
}
