<?php

namespace Modules\Usuario\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Autenticacao\Models\SessaoDeUtilizador;
use Modules\Autenticacao\Service\FotoDoUtilizadorService;
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
            ->tap(fn (LengthAwarePaginator $pagina) => $this->juntarFotosDeAlunos($pagina))
            ->tap(fn (LengthAwarePaginator $pagina) => $this->juntarActividade($pagina));
    }

    /** Janela, em segundos, em que uma sessão conta como «online agora». */
    public const JANELA_ONLINE = 300;

    /**
     * Última actividade real de cada utilizador da página, a partir das sessões que ainda existem (uma só consulta por
     * página). Sem sessão ativa não há como saber o último acesso: fica `null`.
     */
    private function juntarActividade(LengthAwarePaginator $pagina): void
    {
        $ids = $pagina->getCollection()->pluck('id')->all();
        $ultimas = SessaoDeUtilizador::whereIn('user_id', $ids)->groupBy('user_id')
            ->selectRaw('user_id, MAX(last_activity) as ultima')->pluck('ultima', 'user_id');

        $pagina->setCollection($pagina->getCollection()->map(function (array $linha) use ($ultimas) {
            $ultima = $ultimas[$linha['id']] ?? null;

            return [
                ...$linha,
                'ultimo_acesso' => $ultima === null ? null : date('c', (int) $ultima),
                'online' => $ultima !== null && (time() - (int) $ultima) <= self::JANELA_ONLINE,
            ];
        }));
    }

    /**
     * Números para os cartões e separadores: total, ativos, inativos e online agora (do perfil da lista, ou de todos),
     * e quantos utilizadores tem cada perfil. Administradores só contam para quem os pode ver (`autorizacao.ver`).
     *
     * @return array{total: int, ativos: int, inativos: int, online: int, por_perfil: array<string, int>} `por_perfil` inclui `todos`
     */
    public function indicadores(?Perfil $perfil, bool $podeVerAdministradores): array
    {
        $base = fn () => $this->consulta($perfil, []);

        $porPerfil = User::query()
            ->join('user_roles', 'users.id', '=', 'user_roles.users_id')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->selectRaw('roles.nome as perfil, COUNT(DISTINCT users.id) as n')
            ->groupBy('roles.nome')->pluck('n', 'perfil');

        $contagens = ['todos' => User::query()->count()];
        // Chaves = o nome do perfil no endereço da lista (alunos, professores, …), que os separadores usam.
        foreach (ExportacaoDeUtilizadoresService::PERFIS as $nome => $caso) {
            if ($caso === Perfil::ADMIN_ESCOLA && ! $podeVerAdministradores) {
                continue;
            }
            $contagens[$nome] = (int) ($porPerfil[$caso->value] ?? 0);
        }

        return [
            'total' => $base()->count(),
            'ativos' => $base()->where('estado', 1)->count(),
            'inativos' => $base()->where('estado', '!=', 1)->count(),
            'online' => SessaoDeUtilizador::where('last_activity', '>=', time() - self::JANELA_ONLINE)
                ->whereIn('user_id', $base()->select('users.id'))->distinct()->count('user_id'),
            'por_perfil' => $contagens,
        ];
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

        // A foto que o próprio utilizador escolheu tem prioridade sobre a do registo do aluno.
        $pagina->setCollection($pagina->getCollection()->map(
            fn (array $linha) => $linha['avatar'] === null && isset($fotos[$linha['matricula']]) ? [...$linha, 'avatar' => $fotos[$linha['matricula']]] : $linha,
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

    /**
     * Foto de perfil escolhida pelo próprio utilizador. A foto de um administrador só é oferecida a quem pode ver
     * administradores (`autorizacao.ver`): a rota que a serve exige-o, e sem isso o ecrã ficaria com uma imagem partida.
     */
    private function fotoDePerfil(User $user): ?string
    {
        if ($user->roles->contains('nome', Perfil::ADMIN_ESCOLA->value) && ! auth()->user()?->can('autorizacao.ver')) {
            return null;
        }

        return app(FotoDoUtilizadorService::class)->urlParaGestao($user);
    }

    private function serializar(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $this->fotoDePerfil($user),
            'avatarColor' => 'primary',
            'matricula' => $user->numero_matricula,
            'perfis' => $user->roles->pluck('descricao')->all(),
            'e_aluno' => $user->roles->contains('nome', Perfil::ALUNO->value),
            'estado' => $user->estado,
            'ultimo_acesso' => null,
            'online' => false,
            'created_at' => $user->created_at->format('d M Y, H:i'),
        ];
    }
}
