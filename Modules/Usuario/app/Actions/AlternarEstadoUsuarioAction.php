<?php

namespace Modules\Usuario\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Autenticacao\Models\SessaoDeUtilizador;
use Modules\Core\Enums\Estado;
use Modules\Core\Licenciamento\Contracts\GarantiaDeLimites;
use Modules\Core\Licenciamento\Enums\Recurso;
use Modules\Core\Traits\AlternaEstado;
use Modules\Permissao\Enums\Perfil;
use Modules\Usuario\Models\User;
use Modules\Core\Traits\LogService;

class AlternarEstadoUsuarioAction
{
    use LogService;

    use AlternaEstado;

    public function __construct(private GarantiaDeLimites $limites) {}

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(User $user): User
    {
        return $this->comLog('Alternar estado utilizador', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($user));
    }

    private function executarSemRegisto(User $user): User
    {
        return DB::transaction(function () use ($user) {
            if ((int) $user->estado !== Estado::ATIVO->value && $this->ocupaVagaDeUtilizador($user)) {
                $this->limites->garantir(Recurso::UTILIZADORES);
            }

            $user = $this->alternarEstado($user);

            // Desactivar corta o acesso já: quem estava dentro não fica até a sessão expirar.
            if ((int) $user->estado !== Estado::ATIVO->value) {
                $this->terminarAcessos($user);
            }

            return $user;
        });
    }

    /** Sessões web, tokens da API e cookie «lembrar-me» (que reabriria a sessão no pedido seguinte). */
    private function terminarAcessos(User $user): void
    {
        $user->tokens()->delete();
        SessaoDeUtilizador::where('user_id', $user->id)->delete();
        $user->forceFill(['remember_token' => Str::random(60)])->save();
    }

    /** Mesma regra de ContaUtilizadores: só conta quem tem perfil que não seja Aluno, sem ser Administrador. */
    private function ocupaVagaDeUtilizador(User $user): bool
    {
        $perfis = $user->roles()->pluck('nome');

        if ($perfis->contains(Perfil::ADMIN_ESCOLA->value)) {
            return false;
        }

        return $perfis->isEmpty() || $perfis->contains(fn ($nome) => $nome !== Perfil::ALUNO->value);
    }
}
