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
    public function login()
    {
        return view('autenticacao::lixoParaApagar.login');
    }

    public function logout(Request $request)
    {
    }
    public function logoutAllDevices(Request $request)
    {

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
