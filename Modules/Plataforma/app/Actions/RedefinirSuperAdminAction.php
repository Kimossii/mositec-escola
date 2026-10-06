<?php

namespace Modules\Plataforma\Actions;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Core\Tenancy\Provisioning\CredencialInicial;
use Modules\Core\Tenancy\Provisioning\GeradorSenhaTemporaria;
use Modules\Plataforma\Exceptions\SuperAdminNaoRedefinivel;
use Modules\Plataforma\Models\SuperAdmin;

/**
 * Recupera o acesso de um super admin: nova senha temporária (só o hash fica gravado), troca
 * obrigatória no próximo acesso e remember_token rodado. Recusa contas desactivadas.
 *
 * Sessões: NÃO se apagam linhas de `sessions` por user_id. As sessões da Plataforma têm user_id
 * nulo (D5), logo um user_id igual ao id do super admin só pode ser de um utilizador de escola,
 * e apagá-lo derrubaria uma sessão alheia. As sessões abertas deste super admin invalidam-se
 * porque a password muda: a impressão da credencial que guardam deixa de coincidir e o middleware
 * SuperAdminActivo termina-as no pedido seguinte.
 */
class RedefinirSuperAdminAction
{
    public function __construct(private readonly GeradorSenhaTemporaria $senhas) {}

    /**
     * @throws SuperAdminNaoRedefinivel
     */
    public function executar(string $email): CredencialInicial
    {
        $email = mb_strtolower(trim($email));
        $admin = $email === '' ? null : SuperAdmin::query()->where('email', $email)->first();

        if ($admin === null) {
            throw new SuperAdminNaoRedefinivel('Nenhum super admin corresponde ao pedido.');
        }

        if (! $admin->estaActivo()) {
            throw new SuperAdminNaoRedefinivel('O super admin está desactivado: reactive a conta antes de recuperar o acesso.');
        }

        $senha = $this->senhas->gerar();

        $admin->forceFill([
            'password' => Hash::make($senha),
            'deve_alterar_senha' => true,
            'remember_token' => Str::random(60),
        ])->save();

        return new CredencialInicial($admin->email, $senha);
    }
}
