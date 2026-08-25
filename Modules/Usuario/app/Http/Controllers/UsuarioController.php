<?php

namespace Modules\Usuario\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use Log;
use Modules\Usuario\Actions\UsuarioAction;
use Modules\Usuario\DTO\UsuarioDTO;
use Modules\Usuario\Http\Requests\CriarUsuarioRequest;
class UsuarioController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return \Inertia\Inertia::render('Usuario/Index');
    }

    /**
     * Lista apenas usuários com dados_pessoas.tipo_pessoa = Aluno.
     * TODO: como index(), ainda não recebe props reais — ver TIPO_PESSOA em
     * Modules/Usuario/resources/js/Models/Usuario.js.
     */
    public function alunos()
    {
        return \Inertia\Inertia::render('Usuario/Alunos');
    }

    /**
     * Lista apenas usuários com dados_pessoas.tipo_pessoa = Professor.
     */
    public function professores()
    {
        return \Inertia\Inertia::render('Usuario/Professores');
    }

    /**
     * Lista apenas usuários com dados_pessoas.tipo_pessoa = Funcionário.
     */
    public function funcionarios()
    {
        return \Inertia\Inertia::render('Usuario/Funcionarios');
    }

    /**
     * Lista usuários com papel de administrador. "Administrador" não é um
     * tipo_pessoa — quando existir integração com o módulo Permissao, este
     * método deve filtrar por role/permissão, não pela tabela dados_pessoas.
     */
    public function administradores()
    {
        return \Inertia\Inertia::render('Usuario/Administradores');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('usuario::create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CriarUsuarioRequest $request)
    {

    }

    /**
     * Show the specified resource.
     */
    public function show($id)
    {
        return view('usuario::show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        return view('usuario::edit');
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
