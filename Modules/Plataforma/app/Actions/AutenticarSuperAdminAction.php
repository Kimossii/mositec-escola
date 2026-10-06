<?php

namespace Modules\Plataforma\Actions;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Modules\Plataforma\Exceptions\CredenciaisInvalidas;
use Modules\Plataforma\Exceptions\DemasiadasTentativasDeLogin;
use Modules\Plataforma\Models\SuperAdmin;
use Modules\Plataforma\Support\LimitadorLoginPlataforma;
use SensitiveParameter;

/**
 * Valida as credenciais de um Super Admin: e-mail, senha e conta activa. Regista o último login e
 * devolve o super admin; não abre a sessão (ver AbrirSessaoDoSuperAdminAction).
 *
 * Qualquer recusa (e-mail inexistente, senha errada, conta desactivada) lança a MESMA excepção
 * genérica e gasta a mesma quota do limitador; o tempo de resposta também não distingue o e-mail
 * inexistente (faz-se um hash na mesma).
 */
class AutenticarSuperAdminAction
{
    public function __construct(private readonly LimitadorLoginPlataforma $limitador) {}

    /**
     * @throws CredenciaisInvalidas
     * @throws DemasiadasTentativasDeLogin
     */
    public function executar(Request $request, string $email, #[SensitiveParameter] string $senha): SuperAdmin
    {
        $segundos = $this->limitador->segundosDeBloqueio($request);

        if ($segundos !== null) {
            Log::warning('Login da Plataforma bloqueado por muitas tentativas', ['ip' => $request->ip(), 'tempo_restante' => $segundos]);

            throw new DemasiadasTentativasDeLogin($segundos);
        }

        $email = mb_strtolower(trim($email));
        $admin = $email === '' ? null : SuperAdmin::query()->where('email', $email)->first();

        if ($admin === null) {
            Hash::make($senha); // custo equivalente ao de Hash::check
        }

        if ($admin === null || ! Hash::check($senha, $admin->password) || ! $admin->estaActivo()) {
            $this->limitador->registarFalha($request);
            Log::warning('Credenciais inválidas no login da Plataforma', ['ip' => $request->ip()]);

            throw new CredenciaisInvalidas;
        }

        $this->limitador->limparConta($request);
        $admin->forceFill(['ultimo_login_em' => now()])->save();

        return $admin;
    }
}
