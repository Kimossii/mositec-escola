<?php

namespace Modules\Autenticacao\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Modules\Autenticacao\Http\Requests\LoginRequest;
use Modules\Autenticacao\Service\GestaoAutenticacao;

class AutenticacaoController extends Controller
{
    /**
     * Display a Login.
     */
    public function login()
    {
        return Inertia::render('Autenticacao/Login')->rootView('layouts.guest');
    }

    public function store(LoginRequest $request, GestaoAutenticacao $gestaoAutenticacao)
    {
        $key = 'login-attempts:' . $request->ip();

        $resposta = $gestaoAutenticacao->login(
            $request->login,
            $request->password,
            $request->boolean('remember'),
            $key
        );

        if (!$resposta['success']) {
            return back()->withErrors(['login' => $resposta['message']])->onlyInput('login');
        }

        $request->session()->regenerate();

        // Full page visit: the guest/login shell and the authenticated app
        // shell are different root Blade views (the header/sidebar mount
        // into DOM anchors that only exist in the app shell), so an SPA
        // visit here would leave them unmounted. Inertia::location() forces
        // a real browser navigation instead of a client-side page swap.
        return Inertia::location('/');
    }

    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Inertia::location('/login');
    }
}
