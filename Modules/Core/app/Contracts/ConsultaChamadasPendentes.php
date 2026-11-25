<?php

namespace Modules\Core\Contracts;

/**
 * Quantas chamadas deviam ter sido feitas e não foram (aulas previstas dos últimos 7 dias, até hoje, sem aula
 * guardada, leccionada ou não), para o dashboard. Implementado pelo módulo Frequencia. Respeita o tenant corrente.
 */
interface ConsultaChamadasPendentes
{
    /** As do utilizador (professor): das turmas e disciplinas que lecciona hoje. */
    public function doUtilizador(int $utilizadorId): int;

    /** As de todas as turmas activas do ano lectivo activo. */
    public function daEscola(): int;
}
