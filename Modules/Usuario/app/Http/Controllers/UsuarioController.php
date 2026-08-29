<?php

namespace Modules\Usuario\Http\Controllers;

use App\Http\Controllers\Controller;

use Inertia\Inertia;
use Modules\Permissao\Enums\Perfil;
use Modules\Usuario\Http\Requests\AtualizarUsuarioRequest;
use Modules\Usuario\Http\Requests\CriarUsuarioRequest;
use Modules\Usuario\Models\User;
use Modules\Usuario\Services\GestaoUsuarioService;
use Modules\Usuario\Services\UsuarioConsultaService;

class UsuarioController extends Controller
{
    public function __construct(
        private GestaoUsuarioService $service,
        private UsuarioConsultaService $consulta,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Inertia::render('Usuario/Index', array_merge([
            'usuarios' => $this->consulta->listarTodos(),
        ], $this->consulta->dadosDeApoio()));
    }

    /**
     * Lista apenas usuários com o perfil Aluno.
     */
    public function alunos()
    {
        return Inertia::render('Usuario/Alunos', array_merge([
            'usuarios' => $this->consulta->listarPorPerfil(Perfil::ALUNO),
        ], $this->consulta->dadosDeApoio()));
    }

    /**
     * Lista apenas usuários com o perfil Professor.
     */
    public function professores()
    {
        return Inertia::render('Usuario/Professores', array_merge([
            'usuarios' => $this->consulta->listarPorPerfil(Perfil::PROFESSOR),
        ], $this->consulta->dadosDeApoio()));
    }

    /**
     * Lista apenas usuários com o perfil Secretário (formulário "Funcionário").
     */
    public function funcionarios()
    {
        return Inertia::render('Usuario/Funcionarios', array_merge([
            'usuarios' => $this->consulta->listarPorPerfil(Perfil::SECRETARIO),
        ], $this->consulta->dadosDeApoio()));
    }

    public function administradores()
    {
        return Inertia::render('Usuario/Administradores', array_merge([
            'usuarios' => $this->consulta->listarPorPerfil(Perfil::ADMIN_ESCOLA),
        ], $this->consulta->dadosDeApoio()));
    }

    public function encarregados()
    {
        return Inertia::render('Usuario/Encarregados', array_merge([
            'usuarios' => $this->consulta->listarPorPerfil(Perfil::ENCARREGADO),
        ], $this->consulta->dadosDeApoio()));
    }

    public function store(CriarUsuarioRequest $request)
    {
        $this->service->criar($request);

        return redirect()->back()->with('success', 'Utilizador criado com sucesso.');
    }

    public function edit(User $user)
    {
        return response()->json($this->consulta->dadosParaEdicao($user));
    }

    public function update(AtualizarUsuarioRequest $request, User $user)
    {
        $this->service->atualizar($user, $request->validated());

        return redirect()->back()->with('success', 'Utilizador atualizado com sucesso.');
    }

    public function destroy(User $user)
    {
        $this->service->eliminar($user);

        return redirect()->back()->with('success', 'Utilizador eliminado com sucesso.');
    }

    public function alternarEstado(User $user)
    {
        $this->service->alternarEstado($user);

        return redirect()->back()->with('success', 'Estado do utilizador atualizado.');
    }
}
