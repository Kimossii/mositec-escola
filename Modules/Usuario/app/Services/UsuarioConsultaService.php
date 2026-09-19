<?php

namespace Modules\Usuario\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Modules\Usuario\Enums\TipoLogin;
use Modules\Usuario\Models\User;

class UsuarioConsultaService
{
    /**
     * Listagem paginada de utilizadores, opcionalmente restrita a um perfil.
     * Filtros: `pesquisa` (nome, email ou matrícula) e `estado` (0/1; vazio
     * = todos). Ordem por nome, com o id como desempate, para a paginação
     * ser estável.
     *
     * @param  array{pesquisa?: ?string, estado?: ?string}  $filtros
     */
    public function listar(?Perfil $perfil = null, array $filtros = [], int $porPagina = 10): LengthAwarePaginator
    {
        return User::with('roles')
            ->when($perfil, fn ($query) => $query->whereHas('roles', fn ($query) => $query->where('nome', $perfil->value)))
            ->when($filtros['pesquisa'] ?? null, function ($query, $pesquisa) {
                $query->where(function ($query) use ($pesquisa) {
                    $query->whereContem('name', $pesquisa)
                        ->orWhereContem('email', $pesquisa)
                        ->orWhereContem('numero_matricula', $pesquisa);
                });
            })
            ->when(
                ($filtros['estado'] ?? '') !== '',
                fn ($query) => $query->where('estado', (int) $filtros['estado']),
            )
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($porPagina)
            ->withQueryString()
            ->through(fn (User $user) => $this->serializar($user));
    }

    public function dadosDeApoio(): array
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
            // O que cada perfil já concede por padrão — usado só para pintar
            // a grelha de overrides com o estado correcto ao abrir o wizard
            // (célula que o perfil já dá deve arrancar Concedida, não Negada).
            // Não é um 3º estado nem é guardado; é só o valor inicial correcto.
            'permissoesPorPerfil' => RolePermissao::whereIn('role_id', $roleIds)
                ->get(['role_id', 'modulo_id', 'acao_id'])
                ->groupBy('role_id')
                ->map(fn ($grupo) => $grupo->map(fn ($p) => ['modulo_id' => $p->modulo_id, 'acao_id' => $p->acao_id])->values()),
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

    private function serializar(User $user): array
    {
        return [
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
        ];
    }
}
