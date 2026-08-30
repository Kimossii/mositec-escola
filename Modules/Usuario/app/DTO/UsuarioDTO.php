<?php

namespace Modules\Usuario\DTO;

use Modules\Core\Enums\Estado;
use Modules\Permissao\Enums\Perfil;
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
        public Estado $estado = Estado::ATIVO,
        public array $matriculasEducandos = [],
        public array $celulas = [],
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
                ? Estado::from($data['estado'])
                : Estado::ATIVO,
            matriculasEducandos: $data['matriculas_educandos'] ?? [],
            celulas: $data['celulas'] ?? [],
        );
    }
}
