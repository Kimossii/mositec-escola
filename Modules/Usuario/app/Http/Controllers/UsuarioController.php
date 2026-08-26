<?php

namespace Modules\Usuario\Http\Controllers;

use App\Http\Controllers\Controller;

use Illuminate\Support\Collection;
use Inertia\Inertia;
use Modules\Permissao\Enums\Perfil;
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
        return Inertia::render('Usuario/Index', [
            'usuarios' => $this->serializar(User::all()),
        ]);
    }

    /**
     * Lista apenas usuários com o perfil Aluno.
     */
    public function alunos()
    {
        return Inertia::render('Usuario/Alunos', [
            'usuarios' => $this->listarPorPerfil(Perfil::ALUNO),
        ]);
    }

    /**
     * Lista apenas usuários com o perfil Professor.
     */
    public function professores()
    {
        return Inertia::render('Usuario/Professores', [
            'usuarios' => $this->listarPorPerfil(Perfil::PROFESSOR),
        ]);
    }

    /**
     * Lista apenas usuários com o perfil Secretário (formulário "Funcionário").
     */
    public function funcionarios()
    {
        return Inertia::render('Usuario/Funcionarios', [
            'usuarios' => $this->listarPorPerfil(Perfil::SECRETARIO),
        ]);
    }

    public function administradores()
    {
        return Inertia::render('Usuario/Administradores', [
            'usuarios' => $this->listarPorPerfil(Perfil::ADMIN_ESCOLA),
        ]);
    }

    public function encarregados()
    {
        return Inertia::render('Usuario/Encarregados', [
            'usuarios' => $this->listarPorPerfil(Perfil::ENCARREGADO),
        ]);
    }

    private function listarPorPerfil(Perfil $perfil): array
    {
        $usuarios = User::whereHas('roles', fn ($query) => $query->where('nome', $perfil->value))->get();

        return $this->serializar($usuarios);
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


}
