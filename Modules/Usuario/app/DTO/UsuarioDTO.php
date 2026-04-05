<?php

namespace Modules\Usuario\DTO;

use Modules\Usuario\Enums\EstadoUsuario;


class UsuarioDTO
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public ?int $dados_pessoa_id = null,
        public EstadoUsuario $estado = EstadoUsuario::ATIVO,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            email: $data['email'],
            password: $data['password'],
            dados_pessoa_id: $data['dados_pessoa_id'] ?? null,
            estado: isset($data['estado'])
            ? EstadoUsuario::from($data['estado'])
            : EstadoUsuario::ATIVO,
        );
    }
}
