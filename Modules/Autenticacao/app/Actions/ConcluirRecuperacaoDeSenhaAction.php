<?php

namespace Modules\Autenticacao\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Autenticacao\Models\SessaoDeUtilizador;
use Modules\Autenticacao\Notifications\SenhaAlteradaNotificacao;
use Modules\Autenticacao\Support\ElegibilidadeParaRecuperarSenha;
use Modules\Autenticacao\Support\HashDeRecuperacaoDeSenha;
use Modules\Core\Traits\LogService;
use Modules\Usuario\Models\User;
use SensitiveParameter;

/**
 * Conclui a recuperação depois de o middleware validar a assinatura e a validade. Revalida TUDO no momento de usar (a conta
 * pode ter mudado entre o pedido e o clique): a conta é procurada só dentro da escola do pedido, continua elegível, e a
 * impressão ainda confere (senha e email iguais aos do pedido), o que faz da ligação um uso único.
 *
 * O 2FA NÃO é tocado: quem o tem continua a precisar do código ao entrar. Termina TODAS as sessões e tokens e avisa o dono.
 */
class ConcluirRecuperacaoDeSenhaAction
{
    use LogService;

    public function __construct(private ElegibilidadeParaRecuperarSenha $elegibilidade) {}

    /** A conta a que a ligação ainda dá direito, ou null se for inválida, já usada ou deixou de ser elegível. */
    public function contaValida(int $utilizadorId, string $hash): ?User
    {
        $utilizador = User::query()->find($utilizadorId);

        if ($utilizador === null || ! HashDeRecuperacaoDeSenha::confere($utilizador, $hash) || ! $this->elegibilidade->permite($utilizador)) {
            return null;
        }

        return $utilizador;
    }

    /** Devolve false se a ligação já não for válida (nada é alterado). Nunca regista a senha. */
    public function executar(int $utilizadorId, string $hash, #[SensitiveParameter] string $nova): bool
    {
        return $this->comLog('Concluir recuperação de senha', ['utilizador_id' => $utilizadorId], fn () => $this->executarSemRegisto($utilizadorId, $hash, $nova));
    }

    private function executarSemRegisto(int $utilizadorId, string $hash, #[SensitiveParameter] string $nova): bool
    {
        $utilizador = $this->contaValida($utilizadorId, $hash);

        if ($utilizador === null) {
            return false;
        }

        DB::transaction(function () use ($utilizador, $nova) {
            $utilizador->forceFill([
                'password' => Hash::make($nova),
                'deve_alterar_senha' => false,
                'remember_token' => Str::random(60),
            ])->save();

            $utilizador->tokens()->delete();
            SessaoDeUtilizador::where('user_id', $utilizador->id)->delete();
        });

        $utilizador->notify(new SenhaAlteradaNotificacao(now()->format('d/m/Y H:i')));

        return true;
    }
}
