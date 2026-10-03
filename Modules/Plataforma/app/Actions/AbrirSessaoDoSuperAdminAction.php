<?php

namespace Modules\Plataforma\Actions;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Plataforma\Models\SuperAdmin;
use Modules\Plataforma\Support\ImpressaoDeCredencial;

/**
 * Abre a sessão do painel para um Super Admin já autenticado: guard `plataforma`, sessão
 * regenerada (contra fixação de sessão) e impressão da credencial registada.
 */
class AbrirSessaoDoSuperAdminAction
{
    public function executar(Request $request, SuperAdmin $admin): void
    {
        Auth::guard('plataforma')->login($admin);

        $request->session()->regenerate();

        ImpressaoDeCredencial::registar($request->session(), $admin);
    }
}
