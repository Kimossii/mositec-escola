<?php

namespace Modules\Financeiro\Tests\Unit;

use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Support\ViolacaoDeChave;
use PDOException;
use Tests\TestCase;

class ViolacaoDeChaveTest extends TestCase
{
    private function consulta(string $driver, string $sqlstate, string $mensagem, ?int $codigoDriver = null): QueryException
    {
        $pdo = new PDOException($mensagem);
        $pdo->errorInfo = [$sqlstate, $codigoDriver, $mensagem];
        // PDOException::$code guarda o SQLSTATE (string) — como no PDO real.
        (new \ReflectionProperty(Exception::class, 'code'))->setValue($pdo, $sqlstate);

        return new QueryException($driver, 'delete from x where id = ?', [1], $pdo);
    }

    public function test_deteta_violacao_de_chave_estrangeira_no_postgres(): void
    {
        $e = $this->consulta('pgsql', '23503', 'SQLSTATE[23503]: Foreign key violation: 7 ERROR: update or delete on table "x" violates foreign key constraint "y_fk"');

        $this->assertTrue(ViolacaoDeChave::e($e));
    }

    public function test_deteta_violacao_de_chave_estrangeira_no_sqlite(): void
    {
        $e = $this->consulta('sqlite', '23000', 'SQLSTATE[23000]: Integrity constraint violation: 19 FOREIGN KEY constraint failed', 19);

        $this->assertTrue(ViolacaoDeChave::e($e));
    }

    public function test_nao_confunde_outras_violacoes_de_integridade(): void
    {
        $unica = $this->consulta('sqlite', '23000', 'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: x.nome', 19);
        $unicaPg = $this->consulta('pgsql', '23505', 'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key');

        $this->assertFalse(ViolacaoDeChave::e($unica));
        $this->assertFalse(ViolacaoDeChave::e($unicaPg));
        $this->assertFalse(ViolacaoDeChave::e(new Exception('FOREIGN KEY constraint failed')));
    }

    public function test_como_validacao_converte_apenas_violacoes_de_chave(): void
    {
        $fk = $this->consulta('pgsql', '23503', 'violates foreign key constraint');

        try {
            ViolacaoDeChave::comoValidacao(fn () => throw $fk);
            $this->fail('Devia lançar ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['eliminar' => ['Não é possível eliminar: existem registos que dependem deste.']],
                $e->errors(),
            );
        }

        $outra = $this->consulta('pgsql', '23505', 'duplicate key');
        $this->expectException(QueryException::class);
        ViolacaoDeChave::comoValidacao(fn () => throw $outra);
    }

    public function test_como_validacao_devolve_o_resultado_da_operacao(): void
    {
        $this->assertSame(42, ViolacaoDeChave::comoValidacao(fn () => 42));
    }
}
