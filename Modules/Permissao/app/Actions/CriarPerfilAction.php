<?php

namespace Modules\Permissao\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Permissao\Models\Role;
use Modules\Core\Actions\RegistarAuditoriaTenantAction;
use Modules\Core\DTO\AuditoriaTenantDTO;
use Modules\Permissao\Support\InstantaneosDeAuditoria;
use Modules\Core\Traits\LogService;

class CriarPerfilAction
{
    use LogService;

    public function __construct(private readonly RegistarAuditoriaTenantAction $auditoria)
    {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(array $dados): Role
    {
        return $this->comLog('Criar perfil', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($dados));
    }

    private function executarSemRegisto(array $dados): Role
    {
        return DB::transaction(function () use ($dados) {
            $role = Role::create([
                'nome' => Role::PERFIL_PERSONALIZADO,
                'descricao' => $dados['descricao'],
                'estado' => $dados['estado'] ?? 1,
            ]);
            $this->auditoria->executar(new AuditoriaTenantDTO('perfil.criado', 'roles', (int) $role->id, [], InstantaneosDeAuditoria::perfil($role)));

            return $role;
        });
    }
}
