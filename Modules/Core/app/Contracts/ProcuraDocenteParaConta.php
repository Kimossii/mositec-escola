<?php

namespace Modules\Core\Contracts;

use Modules\Core\DTO\DocenteParaConta;

/**
 * Procurar o registo de um docente para o módulo Usuario criar a conta (login) desse docente. Implementado pelo módulo
 * Docente, que depende do Usuario: o inverso seria uma dependência circular, por isso o Usuario só conhece este
 * contrato (mesma razão de ProcuraAlunoParaConta). Respeita o tenant corrente: docente de outra escola devolve null,
 * tal como um inexistente.
 */
interface ProcuraDocenteParaConta
{
    public function procurar(int $docenteId): ?DocenteParaConta;

    /**
     * Docentes activos SEM conta do estabelecimento corrente, por nome ou número de identificação (contém), ordenados
     * por nome. Pesquisa vazia devolve os primeiros por nome.
     *
     * @return list<DocenteParaConta>
     */
    public function semConta(string $pesquisa = '', int $limite = 20): array;
}
