<?php

namespace Modules\Autenticacao\Passwords;

use Illuminate\Auth\Passwords\PasswordBrokerManager;

class PasswordBrokerManagerTenant extends PasswordBrokerManager
{
    protected function createTokenRepository(array $config)
    {
        $chave = $this->app['config']['app.key'];

        if (str_starts_with($chave, 'base64:')) {
            $chave = base64_decode(substr($chave, 7));
        }

        return new TokenRepositoryTenant(
            $this->app['db']->connection($config['connection'] ?? null),
            $this->app['hash'],
            $config['table'],
            $chave,
            ($config['expire'] ?? 60) * 60,
            $config['throttle'] ?? 0,
        );
    }
}
