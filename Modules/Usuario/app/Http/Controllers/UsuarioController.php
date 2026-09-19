<?php

namespace Modules\Usuario\Http\Controllers;

use App\Http\Controllers\Controller;

use Illuminate\Http\Request;
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
    public function index(Request $request)
    {
        return Inertia::render('Usuario/Index', array_merge($this->listagem($request, null), $this->consulta->dadosDeApoio()));
    }

    /**
     * Lista apenas usuários com o perfil Aluno.
     */
    public function alunos(Request $request)
    {
        return Inertia::render('Usuario/Alunos', array_merge($this->listagem($request, Perfil::ALUNO), $this->consulta->dadosDeApoio()));
    }

    /**
     * Lista apenas usuários com o perfil Professor.
     */
    public function professores(Request $request)
    {
        return Inertia::render('Usuario/Professores', array_merge($this->listagem($request, Perfil::PROFESSOR), $this->consulta->dadosDeApoio()));
    }

    /**
     * Lista apenas usuários com o perfil Funcionário.
     */
    public function funcionarios(Request $request)
    {
        return Inertia::render('Usuario/Funcionarios', array_merge($this->listagem($request, Perfil::FUNCIONARIO), $this->consulta->dadosDeApoio()));
    }

    public function administradores(Request $request)
    {
        return Inertia::render('Usuario/Administradores', array_merge($this->listagem($request, Perfil::ADMIN_ESCOLA), $this->consulta->dadosDeApoio()));
    }

    public function encarregados(Request $request)
    {
        return Inertia::render('Usuario/Encarregados', array_merge($this->listagem($request, Perfil::ENCARREGADO), $this->consulta->dadosDeApoio()));
    }

    /**
     * @return array{usuarios: mixed, filtros: array<string, mixed>}
     */
    private function listagem(Request $request, ?Perfil $perfil): array
    {
        $filtros = $request->only(['pesquisa', 'estado']);

        return [
            'usuarios' => $this->consulta->listar($perfil, $filtros),
            'filtros' => $filtros,
        ];
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
        $this->authorize('delete', $user);
        $this->service->eliminar($user);

        return redirect()->back()->with('success', 'Utilizador eliminado com sucesso.');
    }

    public function alternarEstado(User $user)
    {
        $this->authorize('alternarEstado', $user);
        $this->service->alternarEstado($user);

        return redirect()->back()->with('success', 'Estado do utilizador atualizado.');
    }
}
