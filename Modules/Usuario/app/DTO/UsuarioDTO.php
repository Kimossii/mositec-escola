<?php

namespace Modules\Usuario\DTO;

use Modules\Permissao\Enums\Perfil;
use Modules\Usuario\Enums\EstadoUsuario;
use Modules\Usuario\Enums\TipoLogin;

class UsuarioDTO
{
    public function __construct(
        public string $name,
        public string $password,
        public Perfil $perfil,
        public TipoLogin $tipoLogin,
        public ?string $email = null,
        public ?int $dados_pessoa_id = null,
        public EstadoUsuario $estado = EstadoUsuario::ATIVO,
        public array $matriculasEducandos = [],
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            password: $data['password'],
            perfil: Perfil::fromSlug($data['perfil']),
            tipoLogin: TipoLogin::fromLabel($data['tipo_login']),
            email: $data['email'] ?? null,
            dados_pessoa_id: $data['dados_pessoa_id'] ?? null,
            estado: isset($data['estado'])
                ? EstadoUsuario::from($data['estado'])
                : EstadoUsuario::ATIVO,
            matriculasEducandos: $data['matriculas_educandos'] ?? [],
        );
    }
}
