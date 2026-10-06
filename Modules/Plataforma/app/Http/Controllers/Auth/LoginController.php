<?php

namespace Modules\Plataforma\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Modules\Plataforma\Actions\AbrirSessaoDoSuperAdminAction;
use Modules\Plataforma\Actions\AutenticarSuperAdminAction;
use Modules\Plataforma\Exceptions\CredenciaisInvalidas;
use Modules\Plataforma\Exceptions\DemasiadasTentativasDeLogin;
use Modules\Plataforma\Http\Requests\LoginRequest;

class LoginController extends Controller
{
    public function create()
    {
        if (Auth::guard('plataforma')->check()) {
            return redirect()->route('plataforma.inicio');
        }

        return Inertia::render('Plataforma/Login');
    }

    public function store(LoginRequest $request, AutenticarSuperAdminAction $autenticar, AbrirSessaoDoSuperAdminAction $abrirSessao)
    {
        try {
            $admin = $autenticar->executar($request, $request->validated('email'), $request->validated('password'));
        } catch (CredenciaisInvalidas|DemasiadasTentativasDeLogin $e) {
            return redirect()->route('plataforma.login')->withErrors(['email' => $e->getMessage()])->onlyInput('email');
        }

        $abrirSessao->executar($request, $admin);

        return redirect()->route('plataforma.inicio');
    }

    public function destroy(Request $request)
    {
        Auth::guard('plataforma')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Visita completa: o painel volta a arrancar sem estado da sessão anterior.
        return Inertia::location(route('plataforma.login'));
    }
}
