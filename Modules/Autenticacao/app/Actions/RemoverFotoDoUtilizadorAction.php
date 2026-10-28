<?php

namespace Modules\Autenticacao\Actions;

use Modules\Usuario\Models\User;
use Modules\Core\Traits\LogService;

class RemoverFotoDoUtilizadorAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(User $user): void
    {
        $this->comLog('Remover foto do utilizador', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($user));
    }

    private function executarSemRegisto(User $user): void
    {
        $anterior = $user->foto_path;

        $user->forceFill(['foto_path' => null])->save();

        AtualizarFotoDoUtilizadorAction::apagar($anterior);
    }
}
