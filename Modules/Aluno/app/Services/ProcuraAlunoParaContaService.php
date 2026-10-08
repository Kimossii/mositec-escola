<?php

namespace Modules\Aluno\Services;

use Modules\Aluno\Models\Aluno;
use Modules\Core\Contracts\ProcuraAlunoParaConta;
use Modules\Core\DTO\AlunoParaConta;

/**
 * Implementação, no módulo Aluno, do contrato que o Usuario usa para procurar o aluno de uma conta.
 * Lê pelo model (scope de tenant) com igualdade exacta: nunca LIKE.
 */
class ProcuraAlunoParaContaService implements ProcuraAlunoParaConta
{
    public function procurar(string $numeroMatricula): ?AlunoParaConta
    {
        $aluno = Aluno::with('dadosPessoa')->where('numero_matricula', $numeroMatricula)->first();

        if ($aluno === null || $aluno->dadosPessoa === null) {
            return null;
        }

        return new AlunoParaConta(
            numeroMatricula: $aluno->numero_matricula,
            nome: $aluno->dadosPessoa->nome_completo,
            dadosPessoaId: $aluno->dados_pessoa_id,
            estado: (int) $aluno->estado,
            estadoDescricao: (string) $aluno->estado_descricao,
            dataNascimento: $aluno->dadosPessoa->data_nascimento?->toDateString(),
            numeroIdentificacao: $aluno->dadosPessoa->numero_identificacao,
        );
    }
}
