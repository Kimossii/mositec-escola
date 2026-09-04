<?php

namespace Modules\Usuario\Services;

use Illuminate\Support\Collection;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Enums\TipoLogin;
use Modules\Usuario\Models\User;

class UsuarioConsultaService
{
    public function listarTodos(): array
    {
        return $this->serializar(User::with('roles')->get());
    }

    public function listarPorPerfil(Perfil $perfil): array
    {
        $usuarios = User::with('roles')
            ->whereHas('roles', fn ($query) => $query->where('nome', $perfil->value))
            ->get();

        return $this->serializar($usuarios);
    }

    public function dadosDeApoio(): array
    {
        $perfis = collect(Perfil::cases())->map(fn (Perfil $perfil) => [
            'id' => Role::where('nome', $perfil->value)->value('id'),
            'slug' => $perfil->slug(),
            'descricao' => $perfil->label(),
        ]);

        return [
            'perfis' => $perfis->values(),
            'modulos' => Modulo::orderBy('nome')->get(['id', 'nome', 'descricao']),
            'acoes' => Acao::orderBy('numero')->get(['id', 'nome']),
        ];
    }

    public function dadosParaEdicao(User $user): array
    {
        $roleSistema = $user->roles->first(fn ($role) => $role->eSistema());

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'tipo_login' => $user->tipo_login === TipoLogin::MATRICULA ? 'matricula' : 'email',
            'matricula' => $user->numero_matricula,
            'perfil' => $roleSistema ? Perfil::from($roleSistema->nome)->slug() : null,
            'celulas' => $user->permissoes()->get(['modulo_id', 'acao_id', 'permitido']),
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
            'perfis' => $user->roles->pluck('descricao')->all(),
            'estado' => $user->estado,
            'ultimo_acesso' => 'Nunca',
            'created_at' => $user->created_at->format('d M Y, H:i'),
        ])->values()->all();
    }
}
