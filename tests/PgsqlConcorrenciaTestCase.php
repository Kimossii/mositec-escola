<?php

namespace Tests;

use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use Throwable;

/**
 * Base dos testes PostgreSQL de CONCORRÊNCIA. Precisam de dados CONFIRMADOS (visíveis a uma segunda
 * ligação), por isso não usam a transacção do RefreshDatabase: usam DatabaseTruncation (migrate:fresh na
 * primeira vez, TRUNCATE nas seguintes) e truncam de novo no fim de cada teste, para não deixar dados aos
 * testes com RefreshDatabase que corram a seguir (que partem de uma base vazia).
 *
 * Mesmas guardas de PgsqlTestCase: só a base `mositec_escola_test`, num servidor local, sem DB_URL;
 * ignorado (skipped) sem as variáveis; grupo `pgsql` obrigatório. Como correr (NÃO `php artisan test`):
 *
 *     DB_CONNECTION=pgsql DB_DATABASE=mositec_escola_test vendor/bin/phpunit --group=pgsql
 */
abstract class PgsqlConcorrenciaTestCase extends TestCase
{
    use DatabaseTruncation;

    public const LIGACAO_CONCORRENTE = 'pgsql_concorrente';

    private bool $baseVerificada = false;

    protected function setUp(): void
    {
        $this->exigirGrupoPgsql();

        if (! PgsqlTestCase::baseDeTestePermitida(getenv('DB_CONNECTION') ?: null, getenv('DB_DATABASE') ?: null)) {
            $this->markTestSkipped('Teste PostgreSQL: corra com DB_CONNECTION=pgsql DB_DATABASE=' . PgsqlTestCase::BASE_DE_TESTE . ' vendor/bin/phpunit --group=pgsql.');
        }

        if (! PgsqlTestCase::urlPermitida(getenv('DB_URL') ?: null)) {
            $this->markTestSkipped('Teste PostgreSQL: DB_URL definido no ambiente; desactive-o (a base efectiva seria a do URL).');
        }

        parent::setUp();
    }

    /**
     * Gancho do DatabaseTruncation, chamado antes do migrate:fresh ou do TRUNCATE: verifica a configuração já
     * carregada e a base REAL a que o PDO se ligou; só então cria a segunda ligação.
     */
    protected function beforeTruncatingDatabase(): void
    {
        $ligacao = config('database.default');
        $motivo = PgsqlTestCase::motivoParaIgnorar(
            $ligacao,
            config("database.connections.{$ligacao}.database"),
            config("database.connections.{$ligacao}.url"),
            config("database.connections.{$ligacao}.host"),
        );

        if ($motivo !== null) {
            $this->markTestSkipped("Teste PostgreSQL: {$motivo}");
        }

        try {
            $baseReal = DB::connection()->selectOne('select current_database() as base')->base ?? null;
        } catch (Throwable $e) {
            $this->markTestSkipped('Teste PostgreSQL: a base ' . PgsqlTestCase::BASE_DE_TESTE . " não responde ({$e->getMessage()}).");
        }

        if (! PgsqlTestCase::baseRealPermitida($baseReal)) {
            DB::disconnect();
            $this->markTestSkipped("Teste PostgreSQL: a base real ligada ({$baseReal}) não é " . PgsqlTestCase::BASE_DE_TESTE . '.');
        }

        config(['database.connections.' . self::LIGACAO_CONCORRENTE => config("database.connections.{$ligacao}")]);
        $this->baseVerificada = true;
    }

    protected function tearDown(): void
    {
        if ($this->baseVerificada && isset($this->app)) {
            DB::purge(self::LIGACAO_CONCORRENTE);
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    /**
     * Uma segunda ligação, independente da principal (outra sessão do PostgreSQL), que desiste de esperar por
     * um bloqueio ao fim de $esperaMs (SQLSTATE 55P03 lock_not_available).
     */
    protected function outraLigacao(int $esperaMs = 300): Connection
    {
        if (! $this->baseVerificada) {
            throw new LogicException('A segunda ligação só existe depois de verificada a base de teste.');
        }

        $ligacao = DB::connection(self::LIGACAO_CONCORRENTE);

        if (! PgsqlTestCase::baseRealPermitida($ligacao->selectOne('select current_database() as base')->base ?? null)) {
            throw new LogicException('A segunda ligação não está na base de teste.');
        }

        $ligacao->statement("SET lock_timeout = '{$esperaMs}ms'");

        return $ligacao;
    }

    private function exigirGrupoPgsql(): void
    {
        foreach ((new ReflectionClass(static::class))->getAttributes(Group::class) as $atributo) {
            if (($atributo->getArguments()[0] ?? null) === 'pgsql') {
                return;
            }
        }

        $this->fail(static::class . " estende PgsqlConcorrenciaTestCase e tem de declarar #[Group('pgsql')].");
    }
}
