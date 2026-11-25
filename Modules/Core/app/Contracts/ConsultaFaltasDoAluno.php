<?php

namespace Modules\Core\Contracts;

/**
 * As faltas de uma matrícula, para o boletim e o ecrã do aluno consumirem sem importar o módulo Frequencia.
 * Implementado pelo módulo Frequencia. Respeita o tenant corrente.
 */
interface ConsultaFaltasDoAluno
{
    /**
     * Só contam registos «falta» de aulas leccionadas (atrasos, presenças e aulas não leccionadas não contam). A
     * unidade é `aulas` (chamada por tempo) ou `dias` (Pré-Escolar e Primário). `por_disciplina` (chave = id da
     * disciplina) só existe no regime por tempo; `por_periodo` segue as datas dos períodos do ano lectivo da matrícula.
     * `com_chamadas` diz se a matrícula tem algum registo de aulas leccionadas (a escola usa a frequência): sem isso
     * os zeros não querem dizer «0 faltas» e quem apresenta os números deve omiti-los. Matrícula sem faltas (ou
     * inexistente) devolve zeros.
     *
     * @return array{
     *     unidade: 'aulas'|'dias',
     *     total: int,
     *     justificadas: int,
     *     injustificadas: int,
     *     com_chamadas: bool,
     *     por_disciplina: array<int, array{total: int, justificadas: int, injustificadas: int}>,
     *     por_periodo: list<array{periodo_id: int, nome: string, total: int, justificadas: int, injustificadas: int}>
     * }
     */
    public function resumoDaMatricula(int $matriculaId): array;
}
