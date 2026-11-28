<?php

namespace Modules\Licenciamento\Tests\Unit;

use Carbon\CarbonImmutable;
use Modules\Licenciamento\Assinatura\Exceptions\DocumentoInvalido;
use Modules\Licenciamento\Documento\DireitosDaLicenca;
use Modules\Licenciamento\Enums\Modalidade;
use Modules\Licenciamento\Enums\ModoDeOperacao;
use Modules\Licenciamento\Services\AvaliadorDeLicenca;
use PHPUnit\Framework\TestCase;

class AvaliadorDeLicencaTest extends TestCase
{
    private const AVISO = 30;

    private function claims(array $extra = []): array
    {
        return array_merge([
            'jti' => 'lic-1',
            'escola' => 'ESC001',
            'instalacao' => null,
            'iat' => '2026-01-01T00:00:00+00:00',
            'nbf' => '2026-01-01T00:00:00+00:00',
            'exp' => '2027-01-01T00:00:00+00:00',
            'tol' => 7,
            'lim' => ['alunos' => 500, 'utilizadores' => null],
            'mod' => ['financeiro', 'avaliacao'],
            'modalidade' => 1,
            'fmt' => 1,
        ], $extra);
    }

    private function direitos(array $extra = []): DireitosDaLicenca
    {
        return DireitosDaLicenca::deClaims($this->claims($extra));
    }

    private function em(string $data): CarbonImmutable
    {
        return CarbonImmutable::parse($data, 'UTC');
    }

    private function modo(?DireitosDaLicenca $d, string $agora, bool $revogada = false): ModoDeOperacao
    {
        return AvaliadorDeLicenca::modo($d, $this->em($agora), self::AVISO, $revogada);
    }

    public function test_sem_licenca_e_bloqueado(): void
    {
        $this->assertSame(ModoDeOperacao::BLOQUEADO, $this->modo(null, '2026-06-01'));
    }

    public function test_revogada_e_bloqueado(): void
    {
        $this->assertSame(ModoDeOperacao::BLOQUEADO, $this->modo($this->direitos(), '2026-06-01', true));
    }

    public function test_dentro_da_validade_e_normal(): void
    {
        $this->assertSame(ModoDeOperacao::NORMAL, $this->modo($this->direitos(), '2026-06-01'));
    }

    public function test_a_aviso_dias_do_fim_e_aviso(): void
    {
        $this->assertSame(ModoDeOperacao::NORMAL, $this->modo($this->direitos(), '2026-12-01T23:59:59Z'));
        $this->assertSame(ModoDeOperacao::AVISO, $this->modo($this->direitos(), '2026-12-02'));
    }

    public function test_aviso_dias_da_licenca_sobrepoe_o_padrao(): void
    {
        $d = $this->direitos(['avi' => 10]);
        $this->assertSame(10, $d->avisoDias);
        $this->assertSame(ModoDeOperacao::NORMAL, $this->modo($d, '2026-12-10'));
        $this->assertSame(ModoDeOperacao::AVISO, $this->modo($d, '2026-12-22'));
    }

    public function test_expirada_dentro_da_tolerancia_e_aviso(): void
    {
        $this->assertSame(ModoDeOperacao::AVISO, $this->modo($this->direitos(), '2027-01-05'));
    }

    public function test_apos_expira_mais_tolerancia_e_bloqueado(): void
    {
        $d = $this->direitos();
        $limite = $d->bloqueiaEm();
        $this->assertSame('2027-01-08T00:00:00+00:00', $limite->toIso8601String());
        $this->assertSame(ModoDeOperacao::AVISO, AvaliadorDeLicenca::modo($d, $limite, self::AVISO));
        $this->assertSame(ModoDeOperacao::BLOQUEADO, AvaliadorDeLicenca::modo($d, $limite->addSecond(), self::AVISO));
    }

    public function test_tolerancia_zero_bloqueia_logo_apos_expirar(): void
    {
        $d = $this->direitos(['tol' => 0]);
        $this->assertSame(ModoDeOperacao::AVISO, AvaliadorDeLicenca::modo($d, $d->expiraEm, self::AVISO));
        $this->assertSame(ModoDeOperacao::BLOQUEADO, AvaliadorDeLicenca::modo($d, $d->expiraEm->addSecond(), self::AVISO));
    }

    public function test_a_tolerancia_conta_uma_so_vez(): void
    {
        $d = $this->direitos();
        $this->assertSame(ModoDeOperacao::BLOQUEADO, AvaliadorDeLicenca::modo($d, $d->expiraEm->addDays(8), self::AVISO));
        $this->assertSame(ModoDeOperacao::BLOQUEADO, AvaliadorDeLicenca::modo($d, $d->expiraEm->addDays(60), self::AVISO));
    }

    public function test_inicio_no_futuro_e_bloqueado(): void
    {
        $this->assertSame(ModoDeOperacao::BLOQUEADO, $this->modo($this->direitos(), '2025-12-31'));
    }

    public function test_de_claims_recusa_expira_antes_de_inicio(): void
    {
        $this->expectException(DocumentoInvalido::class);
        $this->direitos(['exp' => '2025-12-01T00:00:00+00:00']);
    }

    public function test_de_claims_recusa_versao_formato_desconhecida(): void
    {
        $this->expectException(DocumentoInvalido::class);
        $this->direitos(['fmt' => 2]);
    }

    public function test_de_claims_recusa_tolerancia_negativa(): void
    {
        $this->expectException(DocumentoInvalido::class);
        $this->direitos(['tol' => -1]);
    }

    public function test_de_claims_recusa_aviso_e_limites_negativos(): void
    {
        foreach ([['avi' => -1], ['lim' => ['alunos' => -5]]] as $extra) {
            try {
                $this->direitos($extra);
                $this->fail('Devia recusar valores negativos.');
            } catch (DocumentoInvalido) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_de_claims_recusa_campos_em_falta_e_modalidade_desconhecida(): void
    {
        foreach ([['jti' => ''], ['exp' => 'lixo'], ['modalidade' => 9], ['mod' => 'financeiro']] as $extra) {
            try {
                $this->direitos($extra);
                $this->fail('Devia recusar o documento.');
            } catch (DocumentoInvalido) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_limites_sao_opcionais_e_tem_modulo(): void
    {
        $d = $this->direitos(['lim' => ['alunos' => 10]]);
        $this->assertSame(['alunos' => 10, 'utilizadores' => null, 'emails_mes' => null], $d->limites);
        $this->assertSame(Modalidade::ONLINE, $d->modalidade);
        $this->assertTrue($d->temModulo('financeiro'));
        $this->assertFalse($d->temModulo('outro'));
    }

    public function test_claims_ida_e_volta_sao_iguais(): void
    {
        $d = $this->direitos(['avi' => 15, 'instalacao' => 'inst-9', 'modalidade' => 3]);
        $volta = DireitosDaLicenca::deClaims($d->paraClaims());
        $this->assertSame($d->paraClaims(), $volta->paraClaims());
        $this->assertTrue($d->expiraEm->equalTo($volta->expiraEm));
        $this->assertSame(Modalidade::HIBRIDO, $volta->modalidade);
    }

    public function test_tectos_de_tolerancia_aviso_e_limites(): void
    {
        $this->assertSame(3650, $this->direitos(['tol' => 3650])->toleranciaDias);

        foreach ([['tol' => 3651], ['tol' => PHP_INT_MAX], ['avi' => 3651], ['avi' => PHP_INT_MAX], ['lim' => ['alunos' => 10_000_001]]] as $extra) {
            try {
                $this->direitos($extra);
                $this->fail('Devia recusar valores acima do tecto.');
            } catch (DocumentoInvalido) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_aviso_padrao_enorme_nao_lanca(): void
    {
        $modo = AvaliadorDeLicenca::modo($this->direitos(), $this->em('2026-06-01'), PHP_INT_MAX);

        $this->assertSame(ModoDeOperacao::AVISO, $modo); // aviso limitado a 3650 dias: sem excepção
    }
}
