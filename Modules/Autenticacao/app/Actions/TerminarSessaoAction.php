<?php

namespace Modules\Autenticacao\Actions;

use Modules\Autenticacao\Models\SessaoDeUtilizador;
use Modules\Autenticacao\Service\SessoesDoUtilizadorService;
use Modules\Usuario\Models\User;
use Modules\Core\Traits\LogService;

class TerminarSessaoAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(User $user, string $referencia, string $idSessaoActual): bool
    {
        return $this->comLog('Terminar sessão', $this->contextoDeArgumentos(func_get_args()), fn () => $this->executarSemRegisto($user, $referencia, $idSessaoActual));
    }

    /**
     * Termina uma sessão do próprio utilizador, indicada pela referência opaca. A sessão actual nunca se termina
     * aqui (para isso há «Terminar sessão»). Devolve se encontrou e terminou alguma.
     */
    private function executarSemRegisto(User $user, string $referencia, string $idSessaoActual): bool
    {
        $alvo = SessaoDeUtilizador::where('user_id', $user->id)->where('id', '!=', $idSessaoActual)->get()
            ->first(fn (SessaoDeUtilizador $s) => hash_equals(SessoesDoUtilizadorService::referencia($s->id), $referencia));

        return $alvo !== null && SessaoDeUtilizador::where('id', $alvo->id)->where('user_id', $user->id)->delete() > 0;
    }
}
