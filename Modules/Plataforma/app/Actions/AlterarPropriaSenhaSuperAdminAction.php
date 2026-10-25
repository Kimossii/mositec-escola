<?php

namespace Modules\Plataforma\Actions;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Plataforma\Models\SuperAdmin;
use Modules\Plataforma\Support\ImpressaoDeCredencial;
use SensitiveParameter;
use Modules\Core\Traits\LogService;

/**
 * Troca própria da senha: grava o hash, limpa `deve_alterar_senha` e roda o remember_token.
 *
 * As OUTRAS sessões do Super Admin (quem conhecia a senha anterior) deixam de valer porque a
 * impressão da credencial que guardam já não coincide; a sessão que fez a troca regista a nova
 * impressão e mantém o acesso. Não se apagam linhas de `sessions` por `user_id`.
 */
class AlterarPropriaSenhaSuperAdminAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(SuperAdmin $admin, #[SensitiveParameter] string $nova, Session $sessaoActual): void
    {
        $this->comLog('Alterar própria senha super admin', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($admin, $nova, $sessaoActual));
    }

    private function executarSemRegisto(SuperAdmin $admin, #[SensitiveParameter] string $nova, Session $sessaoActual): void
    {
        $admin->forceFill([
            'password' => Hash::make($nova),
            'deve_alterar_senha' => false,
            'remember_token' => Str::random(60),
        ])->save();

        ImpressaoDeCredencial::registar($sessaoActual, $admin);
    }
}
