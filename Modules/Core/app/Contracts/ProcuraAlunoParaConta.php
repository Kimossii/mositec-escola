<?php

namespace Modules\Core\Contracts;

use Modules\Core\DTO\AlunoParaConta;

/**
 * Procurar o registo oficial de um aluno pelo número de matrícula, para o módulo Usuario criar a conta
 * (login) desse aluno. Implementado pelo módulo Aluno, que depende do Usuario: o inverso seria uma
 * dependência circular, por isso o Usuario só conhece este contrato. A procura é exacta e respeita o
 * tenant corrente (aluno de outra escola devolve null, tal como um inexistente). Sem o módulo que o
 * implementa, resolver o contrato falha (nunca devolve "não encontrado").
 */
interface ProcuraAlunoParaConta
{
    public function procurar(string $numeroMatricula): ?AlunoParaConta;
}
