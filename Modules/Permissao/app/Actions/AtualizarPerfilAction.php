<?php

namespace Modules\Permissao\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Support\PermissaoCache;
use Modules\Core\Traits\LogService;

class AtualizarPerfilAction
{
    use LogService;

    public function __construct(
        private readonly PermissaoCache $cache,
        private readonly GarantirAdministradorEfetivoAction $garantirAdministrador,
    ) {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Role $role, array $dados): Role
    {
        return $this->comLog('Atualizar perfil', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($role, $dados));
    }

    private function executarSemRegisto(Role $role, array $dados): Role
    {
        return DB::transaction(function () use ($role, $dados) {
            $role->update([
                'descricao' => $dados['descricao'],
                'estado' => $dados['estado'] ?? $role->estado,
            ]);

            $this->cache->invalidarTudo();
            $this->garantirAdministrador->verificar();

            return $role;
        });
    }
}
