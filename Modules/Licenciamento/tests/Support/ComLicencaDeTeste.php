<?php

namespace Modules\Licenciamento\Tests\Support;

use Carbon\CarbonImmutable;
use Modules\Licenciamento\Assinatura\CodecoPasetoV4Publico;

/**
 * Auxiliar de testes: gera um par de chaves Ed25519 EFÉMERO, regista a pública em config('licenciamento.chaves_publicas')
 * (base64, como em produção) e assina tokens v4.public. Nunca usa chaves reais.
 *
 * Uso: `$this->prepararLicencas()` no setUp; depois `$this->tokenDeTeste([...claims a sobrepor])`.
 * `claimsDeTeste()` devolve claims válidos por omissão (escola MOSI-000001, expira em 2027-01-01).
 */
trait ComLicencaDeTeste
{
    protected string $chavePrivadaDeTeste = '';

    protected string $kidDeTeste = 'teste-1';

    protected function prepararLicencas(bool $activo = true): void
    {
        $par = sodium_crypto_sign_keypair();
        $this->chavePrivadaDeTeste = sodium_crypto_sign_secretkey($par);

        config([
            'licenciamento.activo' => $activo,
            'licenciamento.chaves_publicas' => [$this->kidDeTeste => base64_encode(sodium_crypto_sign_publickey($par))],
        ]);
    }

    /**
     * Configura o emissor como em produção: a chave privada vai para um FICHEIRO temporário (só o caminho fica em config),
     * apagado no fim do teste. `$conteudo` permite gravar conteúdo inválido; por omissão grava a chave do par de teste.
     */
    protected function prepararEmissor(?string $conteudo = null): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'licenciamento-chave-');
        file_put_contents($caminho, $conteudo ?? base64_encode($this->chavePrivadaDeTeste));
        $this->beforeApplicationDestroyed(static function () use ($caminho) {
            @unlink($caminho);
        });

        config([
            'licenciamento.emissor.chave_privada_ficheiro' => $caminho,
            'licenciamento.emissor.kid' => $this->kidDeTeste,
        ]);

        return $caminho;
    }

    /** @param array<string,mixed> $extra */
    protected function claimsDeTeste(array $extra = []): array
    {
        return array_merge([
            'iss' => 'mositec',
            'jti' => 'lic-1',
            'escola' => 'MOSI-000001',
            'instalacao' => null,
            'iat' => '2026-01-01T00:00:00+00:00',
            'nbf' => '2026-01-01T00:00:00+00:00',
            'exp' => '2027-01-01T00:00:00+00:00',
            'tol' => 7,
            'lim' => ['alunos' => 500, 'utilizadores' => null, 'estabelecimentos' => 1],
            'mod' => ['financeiro', 'avaliacao'],
            'modalidade' => 1,
            'fmt' => 1,
        ], $extra);
    }

    /** @param array<string,mixed> $extra */
    protected function tokenDeTeste(array $extra = [], ?string $chavePrivada = null): string
    {
        return (new CodecoPasetoV4Publico)->assinar($this->claimsDeTeste($extra), $chavePrivada ?? $this->chavePrivadaDeTeste, $this->kidDeTeste);
    }

    /** Congela o relógio dentro da validade dos claims de teste. */
    protected function congelarRelogioDeTeste(string $instante = '2026-06-01T00:00:00+00:00'): void
    {
        CarbonImmutable::setTestNow($instante);
    }
}
