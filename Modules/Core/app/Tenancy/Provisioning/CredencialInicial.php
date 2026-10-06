<?php

namespace Modules\Core\Tenancy\Provisioning;

use LogicException;
use SensitiveParameter;

/**
 * Credencial do administrador inicial, com a senha temporária em claro.
 * Só existe em memória, entre o provisionador que a gera e o comando que a mostra uma vez.
 * Não se serializa nem aparece em var_dump/dumps de excepções.
 */
final class CredencialInicial
{
    public function __construct(
        public readonly string $email,
        #[SensitiveParameter] private readonly string $senha,
    ) {}

    public function senha(): string
    {
        return $this->senha;
    }

    public function __debugInfo(): array
    {
        return ['email' => $this->email, 'senha' => '***'];
    }

    public function __serialize(): array
    {
        throw new LogicException('A credencial inicial não pode ser serializada.');
    }

    public function __toString(): string
    {
        return $this->email;
    }
}
