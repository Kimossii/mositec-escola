<?php

namespace Modules\Permissao\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Exceptions\PerfilDeAlunoFixo;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Support\PermissaoCache;
use Modules\Usuario\Models\User;
use Modules\Core\Actions\RegistarAuditoriaTenantAction;
use Modules\Core\DTO\AuditoriaTenantDTO;
use Modules\Permissao\Support\InstantaneosDeAuditoria;
use Modules\Core\Traits\LogService;

class AtribuirPerfilAction
{
    use LogService;

    public function __construct(
        private readonly PermissaoCache $cache,
        private readonly RegistarAuditoriaTenantAction $auditoria,
    )
    {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(User $user, int $roleId): void
    {
        $this->comLog('Atribuir perfil', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($user, $roleId));
    }

    private function executarSemRegisto(User $user, int $roleId): void
    {
        $perfilAluno = Role::where('nome', Perfil::ALUNO->value)->value('id');

        if ($user->ePerfilAluno() || ($perfilAluno !== null && (int) $roleId === (int) $perfilAluno)) {
            throw PerfilDeAlunoFixo::semPerfisExtra();
        }

        DB::transaction(function () use ($user, $roleId) {
            $antes = InstantaneosDeAuditoria::perfisDoUtilizador($user);
            $user->roles()->syncWithoutDetaching([$roleId]);
            $this->cache->esquecerUtilizador($user->id);
            $this->auditoria->executar(new AuditoriaTenantDTO('perfil.atribuido', 'users', (int) $user->id, ['perfis' => $antes], ['perfis' => InstantaneosDeAuditoria::perfisDoUtilizador($user)]));
        });
    }
}
