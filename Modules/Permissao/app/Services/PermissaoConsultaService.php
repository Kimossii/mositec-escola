<?php

namespace Modules\Permissao\Services;

use Illuminate\Support\Collection;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Modules\Usuario\Models\User;

class PermissaoConsultaService
{
    public function listarPerfis(): Collection
    {
        return Role::withCount('users')
            ->with(['permissoes.modulo:id,descricao', 'permissoes.acao:id,nome'])
            ->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'descricao' => $role->descricao,
                'estado' => $role->estado,
                'sistema' => $role->eSistema(),
                'utilizadores_count' => $role->users_count,
                'permissoes' => $role->permissoes->map(fn ($permissao) => [
                    'modulo' => $permissao->modulo->descricao,
                    'acao' => $permissao->acao->nome,
                ])->values(),
            ]);
    }

    public function acoesDisponiveis(): Collection
    {
        return Acao::orderBy('numero')->pluck('nome');
    }

    public function dadosPermissoesDoPerfil(Role $role): array
    {
        return [
            'perfil' => ['id' => $role->id, 'descricao' => $role->descricao],
            'modulos' => Modulo::orderBy('nome')->get(['id', 'nome', 'descricao']),
            'acoes' => Acao::orderBy('numero')->get(['id', 'nome']),
            'marcadas' => $role->permissoes()->get(['modulo_id', 'acao_id']),
        ];
    }

    public function dadosPermissoesDoUtilizador(User $user): array
    {
        return [
            'utilizador' => ['id' => $user->id, 'name' => $user->name],
            'perfis' => Role::get(['id', 'descricao']),
            'perfisAtribuidos' => $user->roles()->pluck('roles.id'),
            'modulos' => Modulo::orderBy('nome')->get(['id', 'nome', 'descricao']),
            'acoes' => Acao::orderBy('numero')->get(['id', 'nome']),
            // União do que os perfis atribuídos já concedem — só para pintar
            // a grelha com o estado correcto (célula que o perfil já dá
            // arranca Concedida, não Negada). Não é guardado nem é um 3º
            // estado; é só o valor inicial correcto de cada célula.
            'permitidasPeloPerfil' => $this->permissoesDosPerfis($user),
            'overrides' => $user->permissoes()->get(['modulo_id', 'acao_id', 'permitido']),
        ];
    }

    private function permissoesDosPerfis(User $user): array
    {
        $roleIds = $user->roles()->pluck('roles.id');

        return RolePermissao::whereIn('role_id', $roleIds)
            ->get(['modulo_id', 'acao_id'])
            ->unique(fn ($permissao) => "{$permissao->modulo_id}-{$permissao->acao_id}")
            ->values()
            ->map(fn ($permissao) => [
                'modulo_id' => $permissao->modulo_id,
                'acao_id' => $permissao->acao_id,
            ])
            ->all();
    }
}
