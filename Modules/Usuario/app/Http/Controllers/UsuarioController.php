<?php

namespace Modules\Usuario\Http\Controllers;

use App\Http\Controllers\Controller;

use Inertia\Inertia;
use Modules\Usuario\Http\Requests\CriarUsuarioRequest;
use Modules\Usuario\Services\GestaoUsuarioService;

class UsuarioController extends Controller
{
      public function __construct(
        private GestaoUsuarioService $service,
    ) {}


    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Inertia::render('Usuario/Index');
    }

    /**
     * Lista apenas usuários com dados_pessoas.tipo_pessoa = Aluno.
     * TODO: como index(), ainda não recebe props reais — ver TIPO_PESSOA em
     * Modules/Usuario/resources/js/Models/Usuario.js.
     */
    public function alunos()
    {
        return Inertia::render('Usuario/Alunos');
    }

    /**
     * Lista apenas usuários com dados_pessoas.tipo_pessoa = Professor.
     */
    public function professores()
    {
        return Inertia::render('Usuario/Professores');
    }

    /**
     * Lista apenas usuários com dados_pessoas.tipo_pessoa = Funcionário.
     */
    public function funcionarios()
    {
        return Inertia::render('Usuario/Funcionarios');
    }

    public function administradores()
    {
        return Inertia::render('Usuario/Administradores');
    }


    public function store(CriarUsuarioRequest $request)
    {
        $this->service->criar($request);

        return redirect()->back()->with('success', 'Utilizador criado com sucesso.');
    }


}
