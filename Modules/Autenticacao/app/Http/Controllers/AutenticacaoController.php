<?php

namespace Modules\Autenticacao\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Autenticacao\Service\GestaoAutenticacao;

class AutenticacaoController extends Controller
{
    /**
     * Display a Login.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);
        $key = 'login-attempts:' . $request->ip();
        $resposta = app(GestaoAutenticacao::class)->login($request->email, $request->password, $key);
        return response()->json($resposta, $resposta['code'] ?? 200);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logout realizado com sucesso']);
    }
    public function logoutAllDevices(Request $request)
    {
        // Revoga todos os tokens do usuário
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Logout de todos os dispositivos realizado com sucesso.']);
    }
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('autenticacao::index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('autenticacao::create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
    }

    /**
     * Show the specified resource.
     */
    public function show($id)
    {
        return view('autenticacao::show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        return view('autenticacao::edit');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
    }
}
