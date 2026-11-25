<?php

namespace Modules\Permissao\Support;

use Modules\Permissao\Enums\Modulo as ModuloEnum;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Modules\Permissao\Models\UserPermissao;
use Modules\Usuario\Models\User;

/** Estados legíveis (antes/depois) para o rasto de auditoria das permissões. */
class InstantaneosDeAuditoria
{
    /** @return list<string> descrições dos perfis do utilizador, ordenadas */
    public static function perfisDoUtilizador(User $user): array
    {
        return $user->roles()->get()->map(fn (Role $r) => $r->descricao ?: (string) $r->nome)->sort()->values()->all();
    }

    /** @return array<string, bool> `modulo.acao` => permitido, só das permissões personalizadas do utilizador */
    public static function permissoesDoUtilizador(User $user): array
    {
        return self::celulas(UserPermissao::where('users_id', $user->id)->get(['modulo_id', 'acao_id', 'permitido']));
    }

    /** @return array<string, bool> `modulo.acao` => true, das permissões do perfil */
    public static function permissoesDoPerfil(Role $role): array
    {
        return self::celulas(RolePermissao::where('role_id', $role->id)->get(['modulo_id', 'acao_id']));
    }

    /**
     * Alinha dois instantâneos pelas mesmas chaves (ausente = null), para que remover uma permissão apareça no `depois`.
     *
     * @param  array<string, mixed>  $antes
     * @param  array<string, mixed>  $depois
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function alinhar(array $antes, array $depois): array
    {
        $chaves = array_unique([...array_keys($antes), ...array_keys($depois)]);
        sort($chaves);

        return [
            array_combine($chaves, array_map(fn ($k) => $antes[$k] ?? null, $chaves)),
            array_combine($chaves, array_map(fn ($k) => $depois[$k] ?? null, $chaves)),
        ];
    }

    /** @return array<string, mixed> */
    public static function perfil(Role $role): array
    {
        return ['descricao' => $role->descricao, 'estado' => (int) $role->estado];
    }

    private static function celulas($linhas): array
    {
        $modulos = Modulo::pluck('nome', 'id');
        $acoes = Acao::pluck('nome', 'id');
        $resultado = [];

        foreach ($linhas as $l) {
            $slug = ModuloEnum::tryFrom((int) ($modulos[$l->modulo_id] ?? -1))?->slug() ?? 'modulo-'.$l->modulo_id;
            $resultado[$slug.'.'.($acoes[$l->acao_id] ?? $l->acao_id)] = $l->permitido ?? true;
        }
        ksort($resultado);

        return $resultado;
    }
}
