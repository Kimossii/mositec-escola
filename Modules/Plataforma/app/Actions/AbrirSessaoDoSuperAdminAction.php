<?php

namespace Modules\Plataforma\Actions;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Plataforma\Models\SuperAdmin;
use Modules\Plataforma\Support\ImpressaoDeCredencial;
use Modules\Core\Traits\LogService;

/**
 * Abre a sessão do painel para um Super Admin já autenticado: guard `plataforma`, sessão
 * regenerada (contra fixação de sessão) e impressão da credencial registada.
 */
class AbrirSessaoDoSuperAdminAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Request $request, SuperAdmin $admin): void
    {
        $this->comLog('Abrir sessão do super admin', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($request, $admin));
    }

    private function executarSemRegisto(Request $request, SuperAdmin $admin): void
    {
        Auth::guard('plataforma')->login($admin);

        $request->session()->regenerate();

        ImpressaoDeCredencial::registar($request->session(), $admin);
    }
}
