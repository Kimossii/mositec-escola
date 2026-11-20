<?php

namespace Modules\Licenciamento\Assinatura;

use SensitiveParameter;

use JsonException;
use Modules\Licenciamento\Assinatura\Exceptions\AssinaturaInvalida;
use Modules\Licenciamento\Assinatura\Exceptions\ChaveDesconhecida;
use Modules\Licenciamento\Assinatura\Exceptions\DocumentoInvalido;
use SodiumException;

/**
 * PASETO v4.public (Ed25519) sobre ext-sodium. O footer transporta {"kid": "..."} e é autenticado pela PAE.
 */
class CodecoPasetoV4Publico implements CodecoDeLicenca
{
    private const CABECALHO = 'v4.public.';

    public function assinar(array $claims, #[SensitiveParameter] string $chavePrivada, string $kid): string
    {
        $payload = json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $footer = json_encode(['kid' => $kid], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $chave = $chavePrivada;
        try {
            $assinatura = sodium_crypto_sign_detached($this->pae(self::CABECALHO, $payload, $footer, ''), $chave);
        } finally {
            sodium_memzero($chave);
        }

        return self::CABECALHO.$this->codificar($payload.$assinatura).'.'.$this->codificar($footer);
    }

    public function verificar(string $token, array $chavesPublicas): array
    {
        if (! str_starts_with($token, self::CABECALHO)) {
            throw new DocumentoInvalido('Versão ou propósito não suportados.');
        }

        $partes = explode('.', $token);
        if (count($partes) !== 4) {
            throw new DocumentoInvalido('Formato inválido.');
        }

        $corpo = $this->descodificar($partes[2]);
        $footer = $this->descodificar($partes[3]);
        if (strlen($corpo) <= SODIUM_CRYPTO_SIGN_BYTES) {
            throw new DocumentoInvalido('Conteúdo demasiado curto.');
        }

        // O kid vem do footer e escolhe a chave, antes de qualquer claim ser lida.
        $kid = $this->kidDoFooter($footer);
        $chave = null;
        foreach ($chavesPublicas as $k => $c) {
            if (hash_equals((string) $k, $kid)) {
                $chave = $c;
                break;
            }
        }
        if ($chave === null) {
            throw new ChaveDesconhecida('Chave desconhecida.');
        }
        if (strlen($chave) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new DocumentoInvalido('Chave pública mal formada.');
        }

        $payload = substr($corpo, 0, -SODIUM_CRYPTO_SIGN_BYTES);
        $assinatura = substr($corpo, -SODIUM_CRYPTO_SIGN_BYTES);

        if (! sodium_crypto_sign_verify_detached($assinatura, $this->pae(self::CABECALHO, $payload, $footer, ''), $chave)) {
            throw new AssinaturaInvalida('Assinatura inválida.');
        }

        try {
            $claims = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DocumentoInvalido('Payload não é JSON.');
        }
        if (! is_array($claims)) {
            throw new DocumentoInvalido('Payload não é um objecto JSON.');
        }

        return $claims;
    }

    private function kidDoFooter(string $footer): string
    {
        try {
            $dados = json_decode($footer, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DocumentoInvalido('Footer não é JSON.');
        }
        if (! is_array($dados) || ! isset($dados['kid']) || ! is_string($dados['kid']) || $dados['kid'] === '') {
            throw new DocumentoInvalido('Footer sem kid.');
        }

        return $dados['kid'];
    }

    /** Pre-Authentication Encoding da especificação PASETO. */
    private function pae(string ...$pecas): string
    {
        $saida = pack('P', count($pecas));
        foreach ($pecas as $peca) {
            $saida .= pack('P', strlen($peca)).$peca;
        }

        return $saida;
    }

    private function codificar(string $bruto): string
    {
        return sodium_bin2base64($bruto, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    private function descodificar(string $texto): string
    {
        try {
            return sodium_base642bin($texto, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (SodiumException) {
            throw new DocumentoInvalido('Base64 inválido.');
        }
    }
}
