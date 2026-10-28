<?php

namespace Modules\Autenticacao\Actions;

use Modules\Autenticacao\Models\SessaoDeUtilizador;
use Modules\Usuario\Models\User;
use Modules\Core\Traits\LogService;

class TerminarOutrasSessoesAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(User $user, string $idSessaoActual): int
    {
        return $this->comLog('Terminar outras sessões', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($user, $idSessaoActual));
    }

    /** Termina todas as sessões do utilizador excepto a actual e devolve quantas terminou. */
    private function executarSemRegisto(User $user, string $idSessaoActual): int
    {
        return SessaoDeUtilizador::where('user_id', $user->id)->where('id', '!=', $idSessaoActual)->delete();
    }
}
