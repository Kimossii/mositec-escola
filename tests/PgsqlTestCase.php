<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use Throwable;

/**
 * Base dos testes que só o PostgreSQL prova (CHECK, índices parciais, bloqueios, concorrência).
 *
 * Correm APENAS contra a base de teste `mositec_escola_test` (nunca a de desenvolvimento
 * `mositec_escola`), estão fora da suite por omissão (grupo `pgsql` excluído no phpunit.xml) e são
 * ignorados (skipped) quando essa base não está configurada ou não responde. A base é recriada com
 * migrate:fresh no início de cada execução; criá-la é tarefa do dono (`createdb mositec_escola_test`).
 *
 * Como correr (NÃO usar `php artisan test`: o comando limpa as variáveis do .env antes de lançar o
 * PHPUnit e a ligação voltaria a SQLite):
 *
 *     DB_CONNECTION=pgsql DB_DATABASE=mositec_escola_test vendor/bin/phpunit --group=pgsql
 *
 * Host, porta, utilizador e palavra-passe vêm do .env (DB_HOST, DB_PORT, DB_USERNAME, DB_PASSWORD).
 * Cada classe concreta declara #[Group('pgsql')] (os atributos PHP não se herdam).
 */
abstract class PgsqlTestCase extends TestCase
{
    use RefreshDatabase;

    public const BASE_DE_TESTE = 'mositec_escola_test';

    public static function baseDeTestePermitida(?string $ligacao, ?string $base): bool
    {
        return $ligacao === 'pgsql' && $base === self::BASE_DE_TESTE;
    }

    /** DB_URL (ou `url` na ligação) faz o Laravel ligar-se à base do URL, ignorando DB_DATABASE: nunca aceitar. */
    public static function urlPermitida(?string $url): bool
    {
        return $url === null || trim($url) === '';
    }

    public static function hostLocal(?string $host): bool
    {
        return in_array($host === null ? null : strtolower(trim($host, '[] ')), ['127.0.0.1', 'localhost', '::1'], true);
    }

    /** Motivo para ignorar o teste com base na configuração (null = pode correr). */
    public static function motivoParaIgnorar(?string $ligacao, ?string $base, ?string $url, ?string $host): ?string
    {
        if (! self::urlPermitida($url)) {
            return 'DB_URL/url definido: a base efectiva pode não ser a de teste.';
        }

        if (! self::baseDeTestePermitida($ligacao, $base)) {
            return "a ligação configurada ({$ligacao}/{$base}) não é a base de teste " . self::BASE_DE_TESTE . '.';
        }

        if (! self::hostLocal($host)) {
            return "o servidor ({$host}) não é local (127.0.0.1, localhost ou ::1).";
        }

        return null;
    }

    /** Verificação da base REAL a que o PDO ficou ligado (`select current_database()`). */
    public static function baseRealPermitida(?string $baseReal): bool
    {
        return $baseReal === self::BASE_DE_TESTE;
    }

    protected function setUp(): void
    {
        $this->exigirGrupoPgsql();

        // Antes de arrancar a aplicação: sem estas variáveis nada toca em nenhuma base.
        if (! self::baseDeTestePermitida(getenv('DB_CONNECTION') ?: null, getenv('DB_DATABASE') ?: null)) {
            $this->markTestSkipped('Teste PostgreSQL: corra com DB_CONNECTION=pgsql DB_DATABASE=' . self::BASE_DE_TESTE . ' vendor/bin/phpunit --group=pgsql.');
        }

        if (! self::urlPermitida(getenv('DB_URL') ?: null)) {
            $this->markTestSkipped('Teste PostgreSQL: DB_URL definido no ambiente; desactive-o (a base efectiva seria a do URL).');
        }

        parent::setUp();
    }

    /**
     * Gancho do RefreshDatabase, chamado antes do migrate:fresh: segunda verificação, já com a
     * configuração carregada (protege contra configuração em cache), e prova de que a base responde.
     */
    protected function beforeRefreshingDatabase()
    {
        $ligacao = config('database.default');
        $base = config("database.connections.{$ligacao}.database");
        $url = config("database.connections.{$ligacao}.url");
        $host = config("database.connections.{$ligacao}.host");

        if (($motivo = self::motivoParaIgnorar($ligacao, $base, $url, $host)) !== null) {
            $this->markTestSkipped("Teste PostgreSQL: {$motivo}");
        }

        try {
            $baseReal = DB::connection()->selectOne('select current_database() as base')->base ?? null;
        } catch (Throwable $e) {
            $this->markTestSkipped('Teste PostgreSQL: a base ' . self::BASE_DE_TESTE . " não responde ({$e->getMessage()}).");
        }

        if (! self::baseRealPermitida($baseReal)) {
            DB::disconnect();
            $this->markTestSkipped("Teste PostgreSQL: a base real ligada ({$baseReal}) não é " . self::BASE_DE_TESTE . '.');
        }
    }

    private function exigirGrupoPgsql(): void
    {
        foreach ((new ReflectionClass(static::class))->getAttributes(Group::class) as $atributo) {
            if (($atributo->getArguments()[0] ?? null) === 'pgsql') {
                return;
            }
        }

        $this->fail(static::class . " estende PgsqlTestCase e tem de declarar #[Group('pgsql')].");
    }
}
