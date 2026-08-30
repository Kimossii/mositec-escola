<?php

namespace Modules\Permissao\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Permissao\Http\Requests\CriarPerfilRequest;
use Modules\Permissao\Http\Requests\SincronizarPermissoesPerfilRequest;
use Modules\Permissao\Http\Requests\SincronizarPermissoesUtilizadorRequest;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Services\GestaoPerfilService;
use Modules\Permissao\Services\PermissaoConsultaService;
use Modules\Usuario\Models\User;

class PermissaoController extends Controller
{
    public function __construct(
        private PermissaoConsultaService $consulta,
        private GestaoPerfilService $gestao,
    ) {}

    public function index()
    {
        return Inertia::render('Permissao/Perfis', [
            'perfis' => $this->consulta->listarPerfis(),
            'acoes' => $this->consulta->acoesDisponiveis(),
        ]);
    }

    public function store(CriarPerfilRequest $request)
    {
        $this->gestao->criarPerfil($request->validated());

        return redirect()->back()->with('success', 'Perfil criado com sucesso.');
    }

    public function update(CriarPerfilRequest $request, Role $role)
    {
        $this->gestao->atualizarPerfil($role, $request->validated());

        return redirect()->back()->with('success', 'Perfil atualizado com sucesso.');
    }

    public function destroy(Role $role)
    {
        $this->gestao->eliminarPerfil($role);

        return redirect()->back()->with('success', 'Perfil eliminado com sucesso.');
    }

    public function alternarEstado(Role $role)
    {
        $this->gestao->alternarEstadoPerfil($role);

        return redirect()->back()->with('success', 'Estado do perfil atualizado.');
    }

    public function permissoesDoPerfil(Role $role)
    {
        return Inertia::render('Permissao/PerfilPermissoes', $this->consulta->dadosPermissoesDoPerfil($role));
    }

    public function sincronizarPermissoesDoPerfil(SincronizarPermissoesPerfilRequest $request, Role $role)
    {
        $this->gestao->sincronizarPermissoesDoPerfil($role, $request->celulas);

        return redirect()->back()->with('success', 'Permissões do perfil atualizadas.');
    }

    public function permissoesDoUtilizador(User $user)
    {
        return Inertia::render('Permissao/UtilizadorPermissoes', $this->consulta->dadosPermissoesDoUtilizador($user));
    }

    public function sincronizarPermissoesDoUtilizador(SincronizarPermissoesUtilizadorRequest $request, User $user)
    {
        $this->gestao->sincronizarPermissoesDoUtilizador($user, $request->celulas);

        return redirect()->back()->with('success', 'Permissões do utilizador atualizadas.');
    }

    public function atribuirPerfil(Request $request, User $user)
    {
        $request->validate(['role_id' => 'required|exists:roles,id']);

        $this->gestao->atribuirPerfil($user, $request->role_id);

        return redirect()->back()->with('success', 'Perfil atribuído.');
    }

    public function removerPerfil(User $user, Role $role)
    {
        $this->gestao->removerPerfil($user, $role);

        return redirect()->back()->with('success', 'Perfil removido.');
    }
}
