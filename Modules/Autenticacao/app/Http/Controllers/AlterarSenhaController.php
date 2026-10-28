<?php

namespace Modules\Autenticacao\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Autenticacao\Http\Requests\AlterarSenhaRequest;
use Modules\Usuario\Actions\AlterarPropriaSenhaAction;

class AlterarSenhaController extends Controller
{
    public function edit(Request $request)
    {
        // Esta página (vista de raiz própria) é só para a troca obrigatória; a troca voluntária vive nas Definições.
        if (! $request->user()->deve_alterar_senha) {
            return redirect()->route('definicoes', ['aba' => 'seguranca']);
        }

        return Inertia::render('Autenticacao/AlterarSenha')->rootView('layouts.guest');
    }

    public function update(AlterarSenhaRequest $request, AlterarPropriaSenhaAction $acao)
    {
        $eraObrigatoria = $request->user()->deve_alterar_senha;

        $acao->executar($request->user(), $request->validated('password'), $request->session()->getId());

        // Troca voluntária (a partir das Definições): fica na mesma página.
        if (! $eraObrigatoria) {
            return back();
        }

        // Visita completa: a vista de raiz muda de guest para a da aplicação (como no login).
        return Inertia::location('/');
    }
}
