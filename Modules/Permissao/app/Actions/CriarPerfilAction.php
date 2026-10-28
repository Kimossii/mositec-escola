<?php

namespace Modules\Permissao\Actions;

use Modules\Permissao\Models\Role;
use Modules\Core\Traits\LogService;

class CriarPerfilAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(array $dados): Role
    {
        return $this->comLog('Criar perfil', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($dados));
    }

    private function executarSemRegisto(array $dados): Role
    {
        return Role::create([
            'nome' => Role::PERFIL_PERSONALIZADO,
            'descricao' => $dados['descricao'],
            'estado' => $dados['estado'] ?? 1,
        ]);
    }
}
