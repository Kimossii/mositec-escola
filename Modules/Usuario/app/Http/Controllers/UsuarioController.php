<?php

namespace Modules\Usuario\Http\Controllers;

use App\Http\Controllers\Controller;

use Illuminate\Support\Collection;
use Inertia\Inertia;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Modules\Usuario\Actions\AtualizarUsuarioAction;
use Modules\Usuario\Enums\TipoLogin;
use Modules\Usuario\Http\Requests\AtualizarUsuarioRequest;
use Modules\Usuario\Http\Requests\CriarUsuarioRequest;
use Modules\Usuario\Models\User;
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
        return Inertia::render('Usuario/Index', array_merge([
            'usuarios' => $this->serializar(User::all()),
        ], $this->dadosDeApoio()));
    }

    /**
     * Lista apenas usuários com o perfil Aluno.
     */
    public function alunos()
    {
        return Inertia::render('Usuario/Alunos', array_merge([
            'usuarios' => $this->listarPorPerfil(Perfil::ALUNO),
        ], $this->dadosDeApoio()));
    }

    /**
     * Lista apenas usuários com o perfil Professor.
     */
    public function professores()
    {
        return Inertia::render('Usuario/Professores', array_merge([
            'usuarios' => $this->listarPorPerfil(Perfil::PROFESSOR),
        ], $this->dadosDeApoio()));
    }

    /**
     * Lista apenas usuários com o perfil Secretário (formulário "Funcionário").
     */
    public function funcionarios()
    {
        return Inertia::render('Usuario/Funcionarios', array_merge([
            'usuarios' => $this->listarPorPerfil(Perfil::SECRETARIO),
        ], $this->dadosDeApoio()));
    }

    public function administradores()
    {
        return Inertia::render('Usuario/Administradores', array_merge([
            'usuarios' => $this->listarPorPerfil(Perfil::ADMIN_ESCOLA),
        ], $this->dadosDeApoio()));
    }

    public function encarregados()
    {
        return Inertia::render('Usuario/Encarregados', array_merge([
            'usuarios' => $this->listarPorPerfil(Perfil::ENCARREGADO),
        ], $this->dadosDeApoio()));
    }

    private function listarPorPerfil(Perfil $perfil): array
    {
        $usuarios = User::whereHas('roles', fn ($query) => $query->where('nome', $perfil->value))->get();

        return $this->serializar($usuarios);
    }

    private function dadosDeApoio(): array
    {
        $perfis = collect(Perfil::cases())->map(fn (Perfil $perfil) => [
            'id' => Role::where('nome', $perfil->value)->value('id'),
            'slug' => $perfil->slug(),
            'descricao' => $perfil->label(),
        ]);

        $roleIds = $perfis->pluck('id')->filter()->values();

        return [
            'perfis' => $perfis->values(),
            'modulos' => Modulo::orderBy('nome')->get(['id', 'nome', 'descricao']),
            'acoes' => Acao::orderBy('numero')->get(['id', 'nome']),
            'permissoesPorPerfil' => RolePermissao::whereIn('role_id', $roleIds)
                ->get(['role_id', 'modulo_id', 'acao_id'])
                ->groupBy('role_id')
                ->map(fn ($grupo) => $grupo->map(fn ($p) => ['modulo_id' => $p->modulo_id, 'acao_id' => $p->acao_id])->values()),
        ];
    }

    /**
     * @param Collection<int, User> $usuarios
     */
    private function serializar(Collection $usuarios): array
    {
        return $usuarios->map(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => null,
            'avatarColor' => 'primary',
            'matricula' => $user->numero_matricula,
            'estado' => $user->estado,
            'ultimo_acesso' => 'Nunca',
            'created_at' => $user->created_at->format('d M Y, H:i'),
        ])->values()->all();
    }

    public function store(CriarUsuarioRequest $request)
    {
        $this->service->criar($request);

        return redirect()->back()->with('success', 'Utilizador criado com sucesso.');
    }

    public function edit(User $user)
    {
        $roleSistema = $user->roles->first(fn ($role) => $role->eSistema());

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'tipo_login' => $user->tipo_login === TipoLogin::MATRICULA ? 'matricula' : 'email',
            'matricula' => $user->numero_matricula,
            'perfil' => $roleSistema ? Perfil::from($roleSistema->nome)->slug() : null,
            'celulas' => $user->permissoes()->get(['modulo_id', 'acao_id', 'permitido']),
        ]);
    }

    public function update(AtualizarUsuarioRequest $request, User $user, AtualizarUsuarioAction $action)
    {
        $action->atualizar($user, $request->validated());

        return redirect()->back()->with('success', 'Utilizador atualizado com sucesso.');
    }


}
