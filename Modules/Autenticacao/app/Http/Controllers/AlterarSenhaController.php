<?php

namespace Modules\Autenticacao\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Modules\Autenticacao\Http\Requests\AlterarSenhaRequest;
use Modules\Usuario\Actions\AlterarPropriaSenhaAction;

class AlterarSenhaController extends Controller
{
    public function edit()
    {
        return Inertia::render('Autenticacao/AlterarSenha')->rootView('layouts.guest');
    }

    public function update(AlterarSenhaRequest $request, AlterarPropriaSenhaAction $acao)
    {
        $acao->executar($request->user(), $request->validated('password'), $request->session()->getId());

        // Visita completa: a vista de raiz muda de guest para a da aplicação (como no login).
        return Inertia::location('/');
    }
}
