<?php

namespace Modules\Autenticacao\Actions;

use Modules\Autenticacao\Enums\ResultadoConfirmacaoDeEmail;
use Modules\Autenticacao\Support\HashDeConfirmacaoDeEmail;
use Modules\Core\Traits\LogService;
use Modules\Usuario\Models\User;

/**
 * Conclui a confirmação depois de a ligação assinada ser validada pelo middleware. É idempotente: abrir a ligação outra
 * vez não muda nada. O utilizador procura-se SEMPRE dentro da escola do pedido (o model é filtrado por tenant), por isso
 * uma ligação de outra escola nunca confirma nada aqui.
 */
class ConfirmarEmailAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(int $utilizadorId, string $hash): ResultadoConfirmacaoDeEmail
    {
        return $this->comLog('Confirmar email', ['utilizador_id' => $utilizadorId], fn () => $this->executarSemRegisto($utilizadorId, $hash));
    }

    private function executarSemRegisto(int $utilizadorId, string $hash): ResultadoConfirmacaoDeEmail
    {
        $utilizador = User::query()->find($utilizadorId);

        if ($utilizador === null || ! HashDeConfirmacaoDeEmail::confere($utilizador, $hash)) {
            return ResultadoConfirmacaoDeEmail::INVALIDO;
        }

        if ($utilizador->email_verified_at !== null) {
            return ResultadoConfirmacaoDeEmail::JA_CONFIRMADO;
        }

        $utilizador->forceFill(['email_verified_at' => now()])->save();

        return ResultadoConfirmacaoDeEmail::CONFIRMADO;
    }
}
