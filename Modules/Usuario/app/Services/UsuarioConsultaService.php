<?php

namespace Modules\Usuario\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Contracts\ProcuraFotosDeAlunos;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Enums\Modulo as ModuloEnum;
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
    public function listar(?Perfil $perfil = null, array $filtros = [], int $porPagina = 15): LengthAwarePaginator
    {
        return $this->consulta($perfil, $filtros)
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($porPagina)
            ->withQueryString()
            ->through(fn (User $user) => $this->serializar($user))
            ->tap(fn (LengthAwarePaginator $pagina) => $this->juntarFotosDeAlunos($pagina));
    }

    /**
     * Contas de aluno mostram a foto do registo do aluno. Só com `aluno.ver` (a rota da foto exige-a:
     * sem isso o ecrã ficaria com imagens partidas) e numa só consulta por página.
     */
    private function juntarFotosDeAlunos(LengthAwarePaginator $pagina): void
    {
        if (! auth()->user()?->can('aluno.ver')) {
            return;
        }

        $matriculas = $pagina->getCollection()->where('e_aluno', true)->pluck('matricula')->filter()->values()->all();
        $fotos = app(ProcuraFotosDeAlunos::class)->urlsPorMatricula($matriculas);

        $pagina->setCollection($pagina->getCollection()->map(
            fn (array $linha) => isset($fotos[$linha['matricula']]) ? [...$linha, 'avatar' => $fotos[$linha['matricula']]] : $linha,
        ));
    }

    /** Utilizadores (com perfis) que cumprem o perfil e os filtros, sem ordem nem paginação. */
    private function consulta(?Perfil $perfil, array $filtros): Builder
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
            );
    }

    /**
     * Todos os utilizadores que cumprem o perfil e os filtros (mesma ordem da lista), para exportar. Sem `$incluirAdministradores`, os administradores ficam de fora. Para no limite de
     * linhas exportáveis + 1, o suficiente para o exportador recusar um resultado demasiado grande.
     *
     * @return list<array{nome: string, email: ?string, matricula: ?string, perfis: string, estado: string, criado_em: string}>
     */
    public function paraExportacao(?Perfil $perfil, array $filtros, int $limite, bool $incluirAdministradores = true): array
    {
        return $this->consulta($perfil, $filtros)
            ->when(! $incluirAdministradores, fn ($query) => $query->whereDoesntHave('roles', fn ($roles) => $roles->where('nome', Perfil::ADMIN_ESCOLA->value)))
            ->orderBy('name')->orderBy('id')->limit($limite)->get()
            ->map(fn (User $user) => [
                'nome' => $user->name,
                'email' => $user->email,
                'matricula' => $user->numero_matricula,
                'perfis' => $user->roles->pluck('descricao')->filter()->implode(', '),
                'estado' => (int) $user->estado === 1 ? 'Ativo' : 'Inativo',
                'criado_em' => $user->created_at?->format('d/m/Y H:i') ?? '',
            ])->all();
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
            // `slug` serve ao front para agrupar os módulos pelo menu do sistema a que pertencem.
            'modulos' => Modulo::orderBy('nome')->get(['id', 'nome', 'descricao'])->each(fn (Modulo $m) => $m->setAttribute('slug', ModuloEnum::tryFrom((int) $m->nome)?->slug())),
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
            'e_aluno' => $user->roles->contains('nome', Perfil::ALUNO->value),
            'estado' => $user->estado,
            'ultimo_acesso' => 'Nunca',
            'created_at' => $user->created_at->format('d M Y, H:i'),
        ];
    }
}
