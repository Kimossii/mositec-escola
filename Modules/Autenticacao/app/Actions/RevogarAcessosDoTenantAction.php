<?php

namespace Modules\Autenticacao\Actions;

use Illuminate\Support\Str;
use Modules\Autenticacao\Models\SessaoDeUtilizador;
use Modules\Autenticacao\Models\TokenDeAcesso;
use Modules\Core\Tenancy\Contracts\RevogaAcessosDoTenant;
use Modules\Usuario\Models\User;

/**
 * Apaga as sessões (tabela `sessions`, sem tenant_id: filtrada pelos utilizadores do tenant
 * corrente, que o scope de User já limita) todos os tokens de API do tenant corrente e roda o remember_token dos utilizadores.
 */
class RevogarAcessosDoTenantAction implements RevogaAcessosDoTenant
{
    public function revogar(): array
    {
        $sessoes = 0;

        // Cada utilizador recebe um remember_token novo: sem isto, os cookies "lembrar-me" voltariam
        // a autenticar depois de reactivar o tenant.
        User::query()->chunkById(500, function ($utilizadores) use (&$sessoes) {
            $sessoes += SessaoDeUtilizador::whereIn('user_id', $utilizadores->modelKeys())->delete();

            foreach ($utilizadores as $utilizador) {
                $utilizador->setRememberToken(Str::random(60));
                $utilizador->save();
            }
        });

        $tokens = TokenDeAcesso::query()->delete();

        return ['sessoes' => $sessoes, 'tokens' => $tokens];
    }
}
