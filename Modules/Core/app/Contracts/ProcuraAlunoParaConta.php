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

    /**
     * Os alunos desta escola cujas fichas (`dados_pessoas`) se indicam, por ordem de matrícula; ids sem aluno
     * (ou de outra escola) ficam de fora. Serve para mostrar o nome e a matrícula dos educandos de um encarregado.
     *
     * @param  list<int>  $dadosPessoaIds
     * @return list<AlunoParaConta>
     */
    public function porPessoas(array $dadosPessoaIds): array;
}
