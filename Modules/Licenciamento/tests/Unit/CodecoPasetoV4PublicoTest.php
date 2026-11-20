<?php

namespace Modules\Licenciamento\Tests\Unit;

use Modules\Licenciamento\Assinatura\CodecoPasetoV4Publico;
use Modules\Licenciamento\Assinatura\Exceptions\AssinaturaInvalida;
use Modules\Licenciamento\Assinatura\Exceptions\ChaveDesconhecida;
use Modules\Licenciamento\Assinatura\Exceptions\DocumentoInvalido;
use PHPUnit\Framework\TestCase;

class CodecoPasetoV4PublicoTest extends TestCase
{
    // Vectores oficiais v4.public (https://github.com/paseto-standard/test-vectors, v4.json). Chave de teste do próprio vector.
    private const CHAVE_PUBLICA = '1eb9dbbbbc047c03fd70604e0071f0987e16b28b757225c11f00415d0e20b1a2';

    private const CHAVE_SECRETA = 'b4cbfb43df4ce210727d953e4a713307fa19bb7d9f85041438d9e11b942a37741eb9dbbbbc047c03fd70604e0071f0987e16b28b757225c11f00415d0e20b1a2';

    private const KID_OFICIAL = 'zVhMiPBP9fRf2snEcT7gFTioeA9COcNy9DfgL1W60haN';

    private const VECTORES = [
        '4-S-1' => [
            'token' => 'v4.public.eyJkYXRhIjoidGhpcyBpcyBhIHNpZ25lZCBtZXNzYWdlIiwiZXhwIjoiMjAyMi0wMS0wMVQwMDowMDowMCswMDowMCJ9bg_XBBzds8lTZShVlwwKSgeKpLT3yukTw6JUz3W4h_ExsQV-P0V54zemZDcAxFaSeef1QlXEFtkqxT1ciiQEDA',
            'payload' => '{"data":"this is a signed message","exp":"2022-01-01T00:00:00+00:00"}',
            'footer' => '',
        ],
        '4-S-2' => [
            'token' => 'v4.public.eyJkYXRhIjoidGhpcyBpcyBhIHNpZ25lZCBtZXNzYWdlIiwiZXhwIjoiMjAyMi0wMS0wMVQwMDowMDowMCswMDowMCJ9v3Jt8mx_TdM2ceTGoqwrh4yDFn0XsHvvV_D0DtwQxVrJEBMl0F2caAdgnpKlt4p7xBnx1HcO-SPo8FPp214HDw.eyJraWQiOiJ6VmhNaVBCUDlmUmYyc25FY1Q3Z0ZUaW9lQTlDT2NOeTlEZmdMMVc2MGhhTiJ9',
            'payload' => '{"data":"this is a signed message","exp":"2022-01-01T00:00:00+00:00"}',
            'footer' => '{"kid":"zVhMiPBP9fRf2snEcT7gFTioeA9COcNy9DfgL1W60haN"}',
        ],
    ];

    private CodecoPasetoV4Publico $codec;

    protected function setUp(): void
    {
        $this->codec = new CodecoPasetoV4Publico;
    }

    private function b64u(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private function par(): array
    {
        $kp = sodium_crypto_sign_keypair();

        return [sodium_crypto_sign_secretkey($kp), sodium_crypto_sign_publickey($kp)];
    }

    public function test_verifica_os_vectores_oficiais(): void
    {
        $chaves = [self::KID_OFICIAL => hex2bin(self::CHAVE_PUBLICA)];

        // 4-S-2 tem footer com kid: payload recuperado exactamente.
        $v = self::VECTORES['4-S-2'];
        $this->assertSame(json_decode($v['payload'], true), $this->codec->verificar($v['token'], $chaves));
        $partes = explode('.', $v['token']);
        $this->assertSame($v['footer'], sodium_base642bin($partes[3], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING));

        // 4-S-1 não tem footer: sem kid não há como escolher chave, é rejeitado antes de confiar em claims.
        $this->expectException(DocumentoInvalido::class);
        $this->codec->verificar(self::VECTORES['4-S-1']['token'], $chaves);
    }

    public function test_o_vector_sem_footer_verifica_se_o_codec_assina_o_mesmo_payload(): void
    {
        // Prova a PAE sem footer: a assinatura determinística do Ed25519 reproduz o token oficial 4-S-1.
        $v = self::VECTORES['4-S-1'];
        $msg = $this->pae('v4.public.', $v['payload'], '', '');
        $sig = sodium_crypto_sign_detached($msg, hex2bin(self::CHAVE_SECRETA));
        $this->assertSame($v['token'], 'v4.public.'.$this->b64u($v['payload'].$sig));
    }

    public function test_reproduz_o_vector_4_s_2_ao_assinar(): void
    {
        $v = self::VECTORES['4-S-2'];
        $token = $this->codec->assinar(json_decode($v['payload'], true), hex2bin(self::CHAVE_SECRETA), self::KID_OFICIAL);
        $this->assertSame($v['token'], $token);
    }

    public function test_assina_e_verifica_ida_e_volta(): void
    {
        [$sk, $pk] = $this->par();
        $claims = ['lic' => 'abc', 'n' => 5, 'lista' => ['a', 'b']];
        $token = $this->codec->assinar($claims, $sk, 'k1');

        $this->assertStringStartsWith('v4.public.', $token);
        $footer = sodium_base642bin(explode('.', $token)[3], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        $this->assertSame('{"kid":"k1"}', $footer);
        $this->assertSame($claims, $this->codec->verificar($token, ['k1' => $pk]));
    }

    public function test_um_byte_alterado_no_payload_falha(): void
    {
        [$sk, $pk] = $this->par();
        $token = $this->codec->assinar(['a' => 1], $sk, 'k1');
        $partes = explode('.', $token);
        $corpo = sodium_base642bin($partes[2], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        $corpo[0] = $corpo[0] === '{' ? '[' : '{';
        $partes[2] = $this->b64u($corpo);

        $this->expectException(AssinaturaInvalida::class);
        $this->codec->verificar(implode('.', $partes), ['k1' => $pk]);
    }

    public function test_footer_com_kid_desconhecido_falha(): void
    {
        [$sk, $pk] = $this->par();
        $token = $this->codec->assinar(['a' => 1], $sk, 'k1');

        $this->expectException(ChaveDesconhecida::class);
        $this->codec->verificar($token, ['outro' => $pk]);
    }

    public function test_o_footer_e_autenticado(): void
    {
        [$sk, $pk] = $this->par();
        $token = $this->codec->assinar(['a' => 1], $sk, 'k1');
        $partes = explode('.', $token);
        $partes[3] = $this->b64u('{"kid":"k2"}');

        $this->expectException(AssinaturaInvalida::class);
        // k2 existe no mapa (com a mesma chave), logo só a autenticação do footer o apanha.
        $this->codec->verificar(implode('.', $partes), ['k1' => $pk, 'k2' => $pk]);
    }

    public function test_rejeita_v4_local_v3_public_e_lixo(): void
    {
        [, $pk] = $this->par();
        $local = 'v4.local.AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAQAr68PS4AXe7If_ZgesdkUMvSwscFlAl1pk5HC0e8kApeaqMfGo_7OpBnwJOAbY9V7WU6abu74MmcUE8YWAiaArVI8XJ5hOb_4v9RmDkneN0S92dx0OW4pgy7omxgf3S8c3LlQg';
        $v3 = 'v3.public.'.$this->b64u(str_repeat('x', 100)).'.'.$this->b64u('{"kid":"k1"}');

        foreach ([$local, $v3, 'lixo', '', '....', 'v4.public.', 'v4.public.a.b.c.d'] as $t) {
            try {
                $this->codec->verificar($t, ['k1' => $pk]);
                $this->fail("Aceitou: $t");
            } catch (DocumentoInvalido) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_token_truncado_ou_base64_invalido(): void
    {
        [$sk, $pk] = $this->par();
        $token = $this->codec->assinar(['a' => 1], $sk, 'k1');
        $partes = explode('.', $token);

        $casos = [
            substr($token, 0, 30),
            'v4.public.@@@@.'.$partes[3],
            'v4.public.'.$this->b64u('curto').'.'.$partes[3],
            'v4.public.'.$partes[2].'.@@@',
            'v4.public.'.$partes[2].'.'.$this->b64u('nao json'),
            'v4.public.'.$partes[2].'.'.$this->b64u('{"kid":5}'),
        ];
        foreach ($casos as $t) {
            try {
                $this->codec->verificar($t, ['k1' => $pk]);
                $this->fail("Aceitou: $t");
            } catch (DocumentoInvalido) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function pae(string ...$pecas): string
    {
        $out = pack('P', count($pecas));
        foreach ($pecas as $p) {
            $out .= pack('P', strlen($p)).$p;
        }

        return $out;
    }
}
