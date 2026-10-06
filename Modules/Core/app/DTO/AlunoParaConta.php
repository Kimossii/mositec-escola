<?php

namespace Modules\Core\DTO;

/**
 * O mínimo do registo de um aluno de que o módulo Usuario precisa para criar a conta: o que vem do
 * servidor e nunca do cliente. Imutável.
 */
final readonly class AlunoParaConta
{
    public function __construct(
        public string $numeroMatricula,
        public string $nome,
        public int $dadosPessoaId,
        public int $estado,
        public string $estadoDescricao,
        public ?string $dataNascimento = null,
        public ?string $numeroIdentificacao = null,
    ) {}
}
