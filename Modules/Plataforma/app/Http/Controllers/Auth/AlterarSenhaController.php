<?php

namespace Modules\Plataforma\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Modules\Plataforma\Actions\AlterarPropriaSenhaSuperAdminAction;
use Modules\Plataforma\Http\Requests\AlterarSenhaRequest;

class AlterarSenhaController extends Controller
{
    public function edit()
    {
        return Inertia::render('Plataforma/AlterarSenha');
    }

    public function update(AlterarSenhaRequest $request, AlterarPropriaSenhaSuperAdminAction $acao)
    {
        $acao->executar(Auth::guard('plataforma')->user(), $request->validated('password'), $request->session());

        return redirect()->route('plataforma.inicio')->with('success', 'Senha alterada com sucesso.');
    }
}
