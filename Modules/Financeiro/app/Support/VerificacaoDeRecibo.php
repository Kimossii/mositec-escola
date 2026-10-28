<?php

namespace Modules\Financeiro\Support;

use Modules\Financeiro\Models\Pagamento;

/**
 * Verificação pública de recibos: o QR do recibo leva a `/verificar-recibo/{id}-{assinatura}`, na própria escola. A
 * assinatura é um HMAC (com a chave da aplicação) do tenant, do id e do número do pagamento: não se adivinha nem se
 * forja um recibo, e não há nada novo a guardar na base de dados. A página mostra sempre o estado actual do pagamento.
 */
class VerificacaoDeRecibo
{
    private const TAMANHO_ASSINATURA = 20;

    public function token(Pagamento $pagamento): string
    {
        return $pagamento->getKey().'-'.$this->assinatura($pagamento);
    }

    public function url(Pagamento $pagamento): string
    {
        return url('/verificar-recibo/'.$this->token($pagamento));
    }

    /** O pagamento da escola corrente a que o token pertence, ou `null` se o token for inválido ou adulterado. */
    public function pagamentoDoToken(string $token): ?Pagamento
    {
        if (preg_match('/^(\d{1,12})-([a-f0-9]{'.self::TAMANHO_ASSINATURA.'})$/D', $token, $partes) !== 1) {
            return null;
        }

        $pagamento = Pagamento::query()->find((int) $partes[1]);

        return $pagamento !== null && hash_equals($this->assinatura($pagamento), $partes[2]) ? $pagamento : null;
    }

    private function assinatura(Pagamento $pagamento): string
    {
        return substr(hash_hmac('sha256', "recibo|{$pagamento->tenant_id}|{$pagamento->getKey()}|{$pagamento->numero}", (string) config('app.key')), 0, self::TAMANHO_ASSINATURA);
    }
}
