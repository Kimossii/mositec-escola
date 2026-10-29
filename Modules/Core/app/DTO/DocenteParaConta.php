<?php

namespace Modules\Core\DTO;

/**
 * O mínimo do registo de um docente de que o módulo Usuario precisa para criar (ou propor) a conta: o que vem do
 * servidor e nunca do cliente. `email` é o email de contacto da pessoa (sugestão para o login, nunca imposto). Imutável.
 */
final readonly class DocenteParaConta
{
    public function __construct(
        public int $id,
        public string $nome,
        public int $dadosPessoaId,
        public int $estado,
        public ?string $numeroIdentificacao = null,
        public ?string $email = null,
    ) {}
}
