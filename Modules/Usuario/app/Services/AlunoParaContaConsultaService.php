<?php

namespace Modules\Usuario\Services;

use Modules\Core\Contracts\ProcuraAlunoParaConta;
use Modules\Core\Contracts\ProcuraSituacaoAcademicaDoAluno;
use Modules\Core\DTO\AlunoParaConta;
use Modules\Usuario\Models\User;

/**
 * Leitura para o cadastro de utilizadores com perfil aluno: procura o aluno pelo número de matrícula
 * (via contrato do Core, já filtrado pelo tenant) e diz se já tem conta.
 */
class AlunoParaContaConsultaService
{
    public function __construct(private ProcuraAlunoParaConta $procuraAluno,
        private ProcuraSituacaoAcademicaDoAluno $procuraSituacao,
    ) {}

    public function procurar(string $numeroMatricula): ?AlunoParaConta
    {
        return $this->procuraAluno->procurar($numeroMatricula);
    }

    public function jaTemConta(AlunoParaConta $aluno): bool
    {
        return User::where('numero_matricula', $aluno->numeroMatricula)
            ->orWhere('dados_pessoa_id', $aluno->dadosPessoaId)
            ->exists();
    }

    /**
     * Só o que o ecrã precisa para mostrar: nada de ids, documentos ou outros dados pessoais.
     * Os dados que identificam melhor o aluno (nascimento, identificação, curso, turma) só vão se
     * `$comDadosDeIdentificacao` (decidido no servidor: o utilizador tem aluno.ver).
     *
     * @return array<string, mixed>|null
     */
    public function resumo(string $numeroMatricula, bool $comDadosDeIdentificacao = false): ?array
    {
        $aluno = $this->procurar($numeroMatricula);

        if ($aluno === null) {
            return null;
        }

        $resumo = [
            'nome' => $aluno->nome,
            'estado' => $aluno->estado,
            'estado_descricao' => $aluno->estadoDescricao,
            'ja_tem_conta' => $this->jaTemConta($aluno),
        ];

        if (! $comDadosDeIdentificacao) {
            return $resumo;
        }

        $situacao = $this->procuraSituacao->procurar($aluno->numeroMatricula);

        return $resumo + [
            'data_nascimento' => $aluno->dataNascimento,
            'numero_identificacao' => $aluno->numeroIdentificacao,
            'curso' => $situacao->curso,
            'turma' => $situacao->turma,
        ];
    }
}
