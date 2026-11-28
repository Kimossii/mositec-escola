<?php

namespace Modules\Core\Comunicacao\Email\Dominio;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Modules\Core\Comunicacao\Email\EstadoConfiguracaoEmail;
use Throwable;

/** Cliente da API de domínios do Resend. Usa a chave do mailer `resend_dominios` (a mesma com que se envia, senão o domínio ficaria noutra conta); a chave nunca aparece em mensagens nem em logs. */
class ClienteResend implements ClienteDeDominios
{
    private const BASE = 'https://api.resend.com';

    public function registar(string $dominio): array
    {
        $dados = $this->pedir(fn (PendingRequest $http) => $http->post(self::BASE.'/domains', ['name' => $dominio]));

        $id = $dados['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new ClienteDeDominiosIndisponivel('Resposta inesperada do fornecedor de email.');
        }

        return ['id' => $id, 'registos' => $this->registos($dados['records'] ?? [])];
    }

    public function verificar(string $idExterno): EstadoConfiguracaoEmail
    {
        $this->pedir(fn (PendingRequest $http) => $http->post(self::BASE.'/domains/'.rawurlencode($idExterno).'/verify'));
        $dados = $this->pedir(fn (PendingRequest $http) => $http->get(self::BASE.'/domains/'.rawurlencode($idExterno)));

        return match ($dados['status'] ?? null) {
            'verified' => EstadoConfiguracaoEmail::VERIFICADO,
            'failed', 'temporary_failure' => EstadoConfiguracaoEmail::COM_FALHA,
            default => EstadoConfiguracaoEmail::POR_VERIFICAR,
        };
    }

    /** @return array<string, mixed> */
    private function pedir(callable $chamada): array
    {
        $chave = config('mail.mailers.resend_dominios.key');

        if (! is_string($chave) || trim($chave) === '') {
            throw new ClienteDeDominiosIndisponivel('O envio por domínio próprio ainda não está disponível nesta instalação.');
        }

        try {
            $resposta = $chamada(Http::withToken($chave)->acceptJson()->timeout(15));
        } catch (Throwable) {
            throw new ClienteDeDominiosIndisponivel('Não foi possível contactar o fornecedor de email.');
        }

        if ($resposta->failed()) {
            throw new ClienteDeDominiosIndisponivel('O fornecedor de email recusou o pedido (código '.$resposta->status().').');
        }

        return (array) $resposta->json();
    }

    /** @return list<array{tipo: string, nome: string, valor: string}> */
    private function registos(mixed $records): array
    {
        return collect(is_array($records) ? $records : [])
            ->filter(fn ($r) => is_array($r) && isset($r['type'], $r['name'], $r['value']))
            ->map(fn (array $r) => ['tipo' => (string) $r['type'], 'nome' => (string) $r['name'], 'valor' => (string) $r['value']])
            ->values()
            ->all();
    }
}
