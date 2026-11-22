<?php

namespace Modules\Core\Contracts;

use Carbon\CarbonInterface;

/**
 * O que o módulo Horario precisa de saber da docência, sem importar o módulo Docente: quem é o titular de uma
 * disciplina numa turma, que turmas/disciplinas lecciona alguém e que docentes existem. Implementado pelo Docente.
 * Respeita o tenant corrente e a vigência efectiva das atribuições (como `VerificaAtribuicaoDocente`).
 */
interface ConsultaDocenciaParaHorario
{
    /**
     * O titular em vigor (papel titular, nunca substituto) da disciplina na turma, nessa data; null se não houver.
     *
     * @return array{docente_id: int, nome: string}|null
     */
    public function titularEm(int $turmaId, int $disciplinaId, CarbonInterface $data): ?array;

    /**
     * Os titulares (papel titular) da disciplina na turma que se sobrepõem a [inicio, fim] (AAAA-MM-DD, extremos
     * inclusivos), cada um recortado a esse intervalo e por ordem de início. Serve para comparar docentes ao longo
     * de um período e não num só dia.
     *
     * @return list<array{docente_id: int, inicio: string, fim: string}>
     */
    public function titularesNoIntervalo(int $turmaId, int $disciplinaId, string $inicio, string $fim): array;

    /**
     * Pares (turma, disciplina) que o docente lecciona na data, titular ou substituto.
     *
     * @return list<array{turma_id: int, disciplina_id: int}>
     */
    public function paresEmVigorDoDocente(int $docenteId, CarbonInterface $data): array;

    /**
     * Idem, a partir do id do UTILIZADOR (a conta resolve o docente pela pessoa); conta sem docente → [].
     *
     * @return list<array{turma_id: int, disciplina_id: int}>
     */
    public function paresEmVigorDoUtilizador(int $utilizadorId, CarbonInterface $data): array;

    /**
     * Docentes activos do tenant, por ordem alfabética do nome.
     *
     * @return list<array{id: int, nome: string}>
     */
    public function docentesActivos(): array;
}
