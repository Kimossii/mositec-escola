<?php

namespace Modules\Autenticacao\Provisioning;

use Illuminate\Support\Facades\Hash;
use Modules\Core\Tenancy\Contracts\ProvisionaTenant;
use Modules\Core\Tenancy\Provisioning\ColectorDeCredenciais;
use Modules\Core\Tenancy\Provisioning\CredencialInicial;
use Modules\Core\Tenancy\Provisioning\DadosProvisionamento;
use Modules\Core\Tenancy\Provisioning\GeradorSenhaTemporaria;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Enums\TipoLogin;
use Modules\Usuario\Models\User;

/**
 * Ordem 40: administrador inicial, com perfil ADMIN_ESCOLA e senha temporária aleatória.
 *
 * A senha em claro só existe aqui e no ColectorDeCredenciais (memória do pedido): vai para a BD
 * apenas com hash e `deve_alterar_senha = true` obriga a trocá-la no primeiro acesso (spec §10.4).
 * Nunca é registada em logs, eventos nem excepções.
 */
class ProvisionarAdministradorInicial implements ProvisionaTenant
{
    public function __construct(
        private readonly ColectorDeCredenciais $colector,
        private readonly GeradorSenhaTemporaria $senhas,
    ) {}

    public function ordem(): int
    {
        return 40;
    }

    public function provisionar(TenantAtual $tenant, DadosProvisionamento $dados): void
    {
        $papel = Role::where('nome', Perfil::ADMIN_ESCOLA->value)->firstOrFail();

        $senha = $this->senhas->gerar();

        $admin = User::create([
            'name' => $dados->nomeAdministrador,
            'email' => $dados->emailAdministrador,
            'password' => Hash::make($senha),
            'tipo_login' => TipoLogin::EMAIL,
            'estado' => 1,
        ]);
        $admin->forceFill(['deve_alterar_senha' => true])->save();
        $admin->roles()->attach($papel->id);

        $this->colector->registar(new CredencialInicial($dados->emailAdministrador, $senha));
    }
}
