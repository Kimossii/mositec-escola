<?php

namespace Modules\Autenticacao\Actions;

use Modules\Autenticacao\Exceptions\AdministradorNaoRecuperavel;
use Modules\Core\Tenancy\Enums\MotivoRecusaRecuperacao;
use Modules\Core\Tenancy\Provisioning\CredencialInicial;
use Modules\Core\Tenancy\Provisioning\GeradorSenhaTemporaria;
use Modules\Permissao\Enums\Perfil;
use Modules\Usuario\Actions\RedefinirSenhaUsuarioAction;
use Modules\Usuario\Models\User;

/**
 * Recupera o acesso de um administrador (ADMIN_ESCOLA) do tenant corrente quando a senha
 * temporária se perdeu: nova senha temporária, só o hash gravado, troca obrigatória no
 * próximo acesso, sessões, tokens e remember_token invalidados.
 *
 * Reutiliza RedefinirSenhaUsuarioAction sem autor: `senha_redefinida_por` fica nulo, o que
 * distingue uma recuperação por linha de comandos de uma redefinição feita por um utilizador.
 * A senha em claro só sai no valor devolvido; nunca é registada.
 */
class RecuperarAdministradorAction
{
    public function __construct(
        private readonly RedefinirSenhaUsuarioAction $redefinir,
        private readonly GeradorSenhaTemporaria $senhas,
    ) {}

    /**
     * @throws AdministradorNaoRecuperavel
     */
    public function executar(?string $email = null): CredencialInicial
    {
        $email = $email === null ? '' : mb_strtolower(trim($email));

        $encontrados = User::query()
            ->whereHas('roles', fn ($roles) => $roles->where('nome', Perfil::ADMIN_ESCOLA->value))
            ->when($email !== '', fn ($q) => $q->whereRaw('LOWER(email) = ?', [$email]))
            ->get();

        if ($encontrados->isEmpty()) {
            throw new AdministradorNaoRecuperavel(
                'Nenhum administrador da escola corresponde ao pedido.',
                $email === '' ? MotivoRecusaRecuperacao::SEM_ADMINISTRADORES : MotivoRecusaRecuperacao::NAO_ENCONTRADO,
            );
        }

        // Só contas activas: não se repõe a senha de uma conta desactivada.
        $administradores = $encontrados->filter(fn (User $u) => (int) $u->estado === 1)->values();

        if ($administradores->isEmpty()) {
            throw new AdministradorNaoRecuperavel('O administrador está desactivado: reactive a conta antes de recuperar o acesso.', MotivoRecusaRecuperacao::DESACTIVADO);
        }

        if ($administradores->count() > 1) {
            throw new AdministradorNaoRecuperavel(
                'Há vários administradores activos; indique um com --email=. Disponíveis: ' . $administradores->pluck('email')->implode(', ') . '.',
                MotivoRecusaRecuperacao::VARIOS_ADMINISTRADORES,
            );
        }

        $admin = $administradores->first();
        $senha = $this->redefinir->executar($admin, null, $this->senhas->gerar());

        return new CredencialInicial($admin->email, $senha);
    }
}
