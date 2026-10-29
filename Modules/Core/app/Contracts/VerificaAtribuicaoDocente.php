<?php

namespace Modules\Core\Contracts;

use Carbon\CarbonInterface;

/**
 * Pergunta única que os módulos consumidores (Avaliação, Área do Professor) fazem à docência:
 * "este professor lecciona esta disciplina nesta turma, hoje?". Implementado pelo módulo Docente, que
 * conhece as atribuições, sem que os consumidores o importem. Respeita o tenant corrente.
 *
 * `$professorId` é sempre o id do UTILIZADOR (a conta autenticada): a implementação resolve o docente pela pessoa
 * ligada à conta; uma conta sem docente não lecciona nada (`false` / lista vazia).
 *
 * A vigência efectiva de uma atribuição é [data_inicio, min(data_fim, fim do ano lectivo)], com os dois
 * extremos inclusivos, ao dia, no fuso da escola; uma data_fim nula vale o fim do ano lectivo.
 */
interface VerificaAtribuicaoDocente
{
    /**
     * Há uma atribuição em vigor (titular ou substituto) do professor a esta disciplina nesta turma?
     * Sem data usa o dia de hoje no fuso da escola.
     */
    public function podeGerir(int $professorId, int $turmaId, int $disciplinaId, ?CarbonInterface $data = null): bool;

    /**
     * As atribuições em vigor do professor hoje (no fuso da escola), opcionalmente de um só ano lectivo.
     * `papel` é o valor inteiro do enum (0 = titular, 1 = substituto); as datas vêm como Y-m-d e
     * `data_fim` pode ser null (vale até ao fim do ano lectivo).
     *
     * @return list<array{atribuicao_id: int, turma_id: int, turma_nome: string, turma_rotulo: string, disciplina_id: int, disciplina_nome: string, papel: int, data_inicio: string, data_fim: ?string}>
     */
    public function atribuicoesEmVigorDoProfessor(int $professorId, ?int $anoLectivoId = null): array;

    /** A turma tem alguma atribuição de docente (de qualquer vigência)? Impede eliminar a turma. */
    public function turmaTemAtribuicoes(int $turmaId): bool;
}
