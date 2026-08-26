<?php

namespace Modules\Permissao\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Permissao\Actions\SincronizarPermissoesPerfilAction;
use Modules\Permissao\Actions\SincronizarPermissoesUtilizadorAction;
use Modules\Permissao\Http\Requests\CriarPerfilRequest;
use Modules\Permissao\Http\Requests\SincronizarPermissoesPerfilRequest;
use Modules\Permissao\Http\Requests\SincronizarPermissoesUtilizadorRequest;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Services\PermissaoConsultaService;
use Modules\Usuario\Models\User;

class PermissaoController extends Controller
{
    public function __construct(
        private SincronizarPermissoesPerfilAction $sincronizarPerfil,
        private SincronizarPermissoesUtilizadorAction $sincronizarUtilizador,
        private PermissaoConsultaService $consulta,
    ) {}

    public function index()
    {
        return Inertia::render('Permissao/Perfis', [
            'perfis' => Role::withCount('users')->get()->map(fn (Role $role) => [
                'id' => $role->id,
                'descricao' => $role->descricao,
                'estado' => $role->estado,
                'sistema' => $role->eSistema(),
                'utilizadores_count' => $role->users_count,
            ]),
        ]);
    }

    public function store(CriarPerfilRequest $request)
    {
        Role::create([
            'nome' => Role::PERFIL_PERSONALIZADO,
            'descricao' => $request->descricao,
            'estado' => $request->estado ?? 1,
        ]);

        return redirect()->back()->with('success', 'Perfil criado com sucesso.');
    }

    public function update(CriarPerfilRequest $request, Role $role)
    {
        $role->update([
            'descricao' => $request->descricao,
            'estado' => $request->estado ?? $role->estado,
        ]);

        return redirect()->back()->with('success', 'Perfil atualizado com sucesso.');
    }

    public function destroy(Role $role)
    {
        if ($role->eSistema()) {
            return redirect()->back()->withErrors(['perfil' => 'Perfis de sistema não podem ser eliminados.']);
        }

        if ($role->users()->exists()) {
            return redirect()->back()->withErrors(['perfil' => 'Este perfil tem utilizadores atribuídos e não pode ser eliminado.']);
        }

        $role->delete();

        return redirect()->back()->with('success', 'Perfil eliminado com sucesso.');
    }

    public function permissoesDoPerfil(Role $role)
    {
        return Inertia::render('Permissao/PerfilPermissoes', [
            'perfil' => ['id' => $role->id, 'descricao' => $role->descricao],
            'modulos' => Modulo::orderBy('nome')->get(['id', 'nome', 'descricao']),
            'acoes' => Acao::orderBy('numero')->get(['id', 'nome']),
            'marcadas' => $role->permissoes()->get(['modulo_id', 'acao_id']),
        ]);
    }

    public function sincronizarPermissoesDoPerfil(SincronizarPermissoesPerfilRequest $request, Role $role)
    {
        $this->sincronizarPerfil->executar($role, $request->celulas);

        return redirect()->back()->with('success', 'Permissões do perfil atualizadas.');
    }

    public function permissoesDoUtilizador(User $user)
    {
        return Inertia::render('Permissao/UtilizadorPermissoes', [
            'utilizador' => ['id' => $user->id, 'name' => $user->name],
            'perfis' => Role::get(['id', 'descricao']),
            'perfisAtribuidos' => $user->roles()->pluck('roles.id'),
            'modulos' => Modulo::orderBy('nome')->get(['id', 'nome', 'descricao']),
            'acoes' => Acao::orderBy('numero')->get(['id', 'nome']),
            'herdadas' => $this->consulta->permissoesHerdadas($user),
            'overrides' => $user->permissoes()->get(['modulo_id', 'acao_id', 'permitido']),
        ]);
    }

    public function sincronizarPermissoesDoUtilizador(SincronizarPermissoesUtilizadorRequest $request, User $user)
    {
        $this->sincronizarUtilizador->executar($user, $request->celulas);

        return redirect()->back()->with('success', 'Permissões do utilizador atualizadas.');
    }

    public function atribuirPerfil(Request $request, User $user)
    {
        $request->validate(['role_id' => 'required|exists:roles,id']);

        $user->roles()->syncWithoutDetaching([$request->role_id]);

        return redirect()->back()->with('success', 'Perfil atribuído.');
    }

    public function removerPerfil(User $user, Role $role)
    {
        $user->roles()->detach($role->id);

        return redirect()->back()->with('success', 'Perfil removido.');
    }
}
