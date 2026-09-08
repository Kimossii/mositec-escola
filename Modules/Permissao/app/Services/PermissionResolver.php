<?php

namespace Modules\Permissao\Services;

use Illuminate\Support\Collection;
use Modules\Core\Enums\Estado;
use Modules\Permissao\Enums\Modulo;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo as ModuloRegistro;
use Modules\Permissao\Models\RolePermissao;
use Modules\Permissao\Models\UserPermissao;
use Modules\Permissao\Support\PermissaoCache;
use Modules\Usuario\Models\User;

class PermissionResolver
{
    private array $memoria = [];

    private ?array $acaoNomesValidos = null;

    public function __construct(private readonly PermissaoCache $cache)
    {
    }

    public function reconhece(string $permissao): bool
    {
        if (!str_contains($permissao, '.')) {
            return false;
        }

        [$moduloSlug, $acaoNome] = explode('.', $permissao, 2);

        return Modulo::fromSlug($moduloSlug) !== null
            && in_array($acaoNome, $this->acaoNomesValidos(), true);
    }

    private function acaoNomesValidos(): array
    {
        if ($this->acaoNomesValidos === null) {
            $this->acaoNomesValidos = Acao::pluck('nome')->all();
        }

        return $this->acaoNomesValidos;
    }

    public function can(User $user, string $permissao): bool
    {
        return in_array($permissao, $this->conjuntoConcedido($user), true);
    }

    /**
     * Limpa a memoização em memória de um utilizador específico.
     *
     * Chamado pela PermissaoCache quando esta invalida a cache persistente
     * de um utilizador, para que esta instância (agora singleton) não
     * continue a devolver um resultado memoizado desatualizado dentro do
     * mesmo request/processo.
     */
    public function esquecerUtilizador(int $userId): void
    {
        unset($this->memoria[$userId]);
    }

    /**
     * Limpa toda a memoização em memória.
     *
     * Chamado pela PermissaoCache quando esta invalida globalmente a cache
     * persistente (mudança de epoch), pelo mesmo motivo do método acima.
     */
    public function esquecerTudo(): void
    {
        $this->memoria = [];
    }

    public function conjuntoConcedido(User $user): array
    {
        if (array_key_exists($user->id, $this->memoria)) {
            return $this->memoria[$user->id];
        }

        $conjunto = $this->cache->obter($user->id);
        if ($conjunto === null) {
            $conjunto = $this->calcular($user);
            $this->cache->guardar($user->id, $conjunto);
        }

        return $this->memoria[$user->id] = $conjunto;
    }

    private function calcular(User $user): array
    {
        $moduloNomesPorId = ModuloRegistro::pluck('nome', 'id');
        $acaoNomesPorId = Acao::pluck('nome', 'id');

        $roleIds = $user->roles()
            ->where('estado', Estado::ATIVO->value)
            ->pluck('roles.id');

        $doPerfil = RolePermissao::whereIn('role_id', $roleIds)
            ->get(['modulo_id', 'acao_id'])
            ->map(fn (RolePermissao $linha) => $this->paraString($linha->modulo_id, $linha->acao_id, $moduloNomesPorId, $acaoNomesPorId))
            ->filter()
            ->toBase();

        $overrides = UserPermissao::where('users_id', $user->id)->get(['modulo_id', 'acao_id', 'permitido']);

        $concedidos = $overrides->filter(fn (UserPermissao $linha) => $linha->permitido === true)
            ->map(fn (UserPermissao $linha) => $this->paraString($linha->modulo_id, $linha->acao_id, $moduloNomesPorId, $acaoNomesPorId))
            ->filter()
            ->toBase();

        $negados = $overrides->filter(fn (UserPermissao $linha) => $linha->permitido === false)
            ->map(fn (UserPermissao $linha) => $this->paraString($linha->modulo_id, $linha->acao_id, $moduloNomesPorId, $acaoNomesPorId))
            ->filter()
            ->all();

        return $doPerfil->merge($concedidos)
            ->unique()
            ->reject(fn (string $permissao) => in_array($permissao, $negados, true))
            ->values()
            ->all();
    }

    private function paraString(int $moduloId, int $acaoId, Collection $moduloNomesPorId, Collection $acaoNomesPorId): ?string
    {
        $moduloNome = $moduloNomesPorId->get($moduloId);
        $modulo = $moduloNome !== null ? Modulo::tryFrom($moduloNome) : null;
        $acaoNome = $acaoNomesPorId->get($acaoId);

        if ($modulo === null || $acaoNome === null) {
            return null;
        }

        return "{$modulo->slug()}.{$acaoNome}";
    }
}
