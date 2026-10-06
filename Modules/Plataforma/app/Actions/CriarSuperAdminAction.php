<?php

namespace Modules\Plataforma\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Tenancy\Provisioning\CredencialInicial;
use Modules\Core\Tenancy\Provisioning\GeradorSenhaTemporaria;
use Modules\Plataforma\Exceptions\DadosDeSuperAdminInvalidos;
use Modules\Plataforma\Models\SuperAdmin;

/**
 * Cria um super admin com senha temporária: só o hash fica na BD, a troca é obrigatória no
 * primeiro acesso, e a senha em claro só sai no valor devolvido (nunca é registada).
 */
class CriarSuperAdminAction
{
    public function __construct(private readonly GeradorSenhaTemporaria $senhas) {}

    /**
     * @throws DadosDeSuperAdminInvalidos
     */
    public function executar(string $nome, string $email): CredencialInicial
    {
        $nome = trim($nome);
        $email = mb_strtolower(trim($email));
        $erros = [];

        if ($nome === '') {
            $erros['nome'] = 'O nome do super admin é obrigatório.';
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $erros['email'] = 'O email do super admin é inválido.';
        } elseif (SuperAdmin::query()->where('email', $email)->exists()) {
            $erros['email'] = "O email '{$email}' já está registado.";
        }

        if ($erros !== []) {
            throw new DadosDeSuperAdminInvalidos($erros);
        }

        $senha = $this->senhas->gerar();

        try {
            $admin = new SuperAdmin(['name' => $nome, 'email' => $email, 'password' => Hash::make($senha)]);
            $admin->deve_alterar_senha = true;
            $admin->save();
        } catch (UniqueConstraintViolationException) {
            throw new DadosDeSuperAdminInvalidos(['email' => "O email '{$email}' já está registado."]);
        }

        return new CredencialInicial($email, $senha);
    }
}
