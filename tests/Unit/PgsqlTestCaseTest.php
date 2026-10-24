<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\PgsqlTestCase;

class PgsqlTestCaseTest extends TestCase
{
    public function test_so_a_base_de_teste_em_postgresql_e_permitida(): void
    {
        $this->assertTrue(PgsqlTestCase::baseDeTestePermitida('pgsql', 'mositec_escola_test'));

        $this->assertFalse(PgsqlTestCase::baseDeTestePermitida('pgsql', 'mositec_escola')); // a base de desenvolvimento, nunca
        $this->assertFalse(PgsqlTestCase::baseDeTestePermitida('pgsql', ':memory:'));
        $this->assertFalse(PgsqlTestCase::baseDeTestePermitida('pgsql', null));
        $this->assertFalse(PgsqlTestCase::baseDeTestePermitida('sqlite', 'mositec_escola_test'));
        $this->assertFalse(PgsqlTestCase::baseDeTestePermitida(null, null));
    }

    public function test_db_url_definido_bloqueia_mesmo_com_a_base_certa(): void
    {
        $this->assertTrue(PgsqlTestCase::urlPermitida(null));
        $this->assertTrue(PgsqlTestCase::urlPermitida(''));
        $this->assertTrue(PgsqlTestCase::urlPermitida('  '));
        $this->assertFalse(PgsqlTestCase::urlPermitida('postgresql://u:p@127.0.0.1/mositec_escola'));

        $this->assertNull(PgsqlTestCase::motivoParaIgnorar('pgsql', 'mositec_escola_test', null, '127.0.0.1'));
        $this->assertStringContainsString('DB_URL', PgsqlTestCase::motivoParaIgnorar('pgsql', 'mositec_escola_test', 'postgresql://u@127.0.0.1/mositec_escola', '127.0.0.1'));
    }

    public function test_so_servidores_locais_sao_permitidos(): void
    {
        foreach (['127.0.0.1', 'localhost', 'LOCALHOST', '::1', '[::1]'] as $host) {
            $this->assertTrue(PgsqlTestCase::hostLocal($host), $host);
            $this->assertNull(PgsqlTestCase::motivoParaIgnorar('pgsql', 'mositec_escola_test', null, $host), $host);
        }

        foreach ([null, '', '10.0.0.5', 'db.exemplo.com', '127.0.0.1.exemplo.com'] as $host) {
            $this->assertFalse(PgsqlTestCase::hostLocal($host), (string) $host);
            $this->assertStringContainsString('não é local', PgsqlTestCase::motivoParaIgnorar('pgsql', 'mositec_escola_test', null, $host) ?? '', (string) $host);
        }
    }

    public function test_a_base_errada_na_configuracao_e_ignorada(): void
    {
        $this->assertNotNull(PgsqlTestCase::motivoParaIgnorar('pgsql', 'mositec_escola', null, '127.0.0.1'));
        $this->assertNotNull(PgsqlTestCase::motivoParaIgnorar('sqlite', 'mositec_escola_test', null, '127.0.0.1'));
    }

    public function test_a_base_real_ligada_tem_de_ser_a_de_teste(): void
    {
        $this->assertTrue(PgsqlTestCase::baseRealPermitida('mositec_escola_test'));
        $this->assertFalse(PgsqlTestCase::baseRealPermitida('mositec_escola')); // DB_URL a apontar a outra base
        $this->assertFalse(PgsqlTestCase::baseRealPermitida(''));
        $this->assertFalse(PgsqlTestCase::baseRealPermitida(null));
    }

    public function test_o_phpunit_exclui_o_grupo_pgsql_por_omissao(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 2) . '/phpunit.xml');
        $excluidos = array_map('strval', $xml->xpath('/phpunit/groups/exclude/group') ?: []);

        $this->assertContains('pgsql', $excluidos);
    }
}
