<?php

namespace Modules\Usuario\App\DTO;

class UsuarioDTO
{
    public function __construct(
        public string $nome,
        public string $email,
        public string $password,
        public ?int $dados_pessoa_id = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            nome: $data['nome'],
            email: $data['email'],
            password: $data['password'],
            dados_pessoa_id: $data['dados_pessoa_id'] ?? null,
        );
    }
}
