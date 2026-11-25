<?php

namespace Modules\Licenciamento\Services;

use Modules\Licenciamento\Exceptions\EmissorIndisponivel;
use SensitiveParameter;

/**
 * Acesso à chave privada de emissão (só a instalação central a tem). A chave vive de preferência num FICHEIRO fora da raiz web (o config
 * só guarda o caminho); sem acesso a ficheiros, aceita-se a variável secreta LICENCIAMENTO_CHAVE_PRIVADA (base64) e é lida no momento de assinar: nunca fica em config, propriedades, logs nem devolvida fora de
 * `chavePrivada()`, que só o emissor chama para assinar.
 */
class EmissorDeLicencas
{
    public function disponivel(): bool
    {
        return $this->motivoIndisponivel() === null;
    }

    /** Razão curta e segura (sem caminhos nem chaves) pela qual não se pode emitir; null se o emissor está operacional. */
    public function motivoIndisponivel(): ?string
    {
        if ($this->kid() === '') {
            return 'Falta o identificador da chave (LICENCIAMENTO_KID).';
        }

        $privada = $this->lerChave();
        if ($privada === null) {
            return 'A chave privada não está disponível: ficheiro em falta, ilegível ou inválido.';
        }

        $publicaConfigurada = config('licenciamento.chaves_publicas.' . $this->kid());
        $publica = is_string($publicaConfigurada) ? base64_decode($publicaConfigurada, true) : false;
        if ($publica === false || ! hash_equals($publica, sodium_crypto_sign_publickey_from_secretkey($privada))) {
            return 'A chave privada não corresponde à chave pública configurada para este kid.';
        }

        return null;
    }

    public function kid(): string
    {
        return trim((string) config('licenciamento.emissor.kid'));
    }

    /**
     * @return string chave privada binária (64 bytes)
     *
     * @throws EmissorIndisponivel
     */
    public function chavePrivada(): string
    {
        if ($this->kid() === '' || ($chave = $this->lerChave()) === null) {
            throw new EmissorIndisponivel;
        }

        return $chave;
    }

    private function lerChave(): ?string
    {
        $caminho = config('licenciamento.emissor.chave_privada_ficheiro');
        if (is_string($caminho) && $caminho !== '') {
            if (! is_file($caminho) || ! is_readable($caminho)) {
                return null;
            }

            $conteudo = @file_get_contents($caminho);

            return is_string($conteudo) ? $this->decodificar(trim($conteudo)) : null;
        }

        // Alternativa para hospedagens sem acesso a ficheiros/volumes: a chave em base64 numa variável de ambiente secreta.
        $emVariavel = config('licenciamento.emissor.chave_privada');

        return is_string($emVariavel) && trim($emVariavel) !== '' ? $this->decodificar(trim($emVariavel)) : null;
    }

    private function decodificar(#[SensitiveParameter] string $base64): ?string
    {
        $binaria = base64_decode($base64, true);

        return $binaria !== false && strlen($binaria) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES ? $binaria : null;
    }
}
