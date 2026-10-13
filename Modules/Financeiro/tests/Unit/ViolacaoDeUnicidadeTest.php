<?php

namespace Modules\Financeiro\Tests\Unit;

use Exception;
use Illuminate\Database\QueryException;
use LogicException;
use Modules\Financeiro\Support\ViolacaoDeUnicidade;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class ViolacaoDeUnicidadeTest extends TestCase
{
    private function consulta(string $driver, string $sqlstate, string $mensagem, ?int $codigoDriver = null): QueryException
    {
        $pdo = new PDOException($mensagem);
        $pdo->errorInfo = [$sqlstate, $codigoDriver, $mensagem];
        // PDOException::$code guarda o SQLSTATE (string), como no PDO real.
        (new ReflectionProperty(Exception::class, 'code'))->setValue($pdo, $sqlstate);

        return new QueryException($driver, 'insert into propinas (x) values (?)', [1], $pdo);
    }

    public function test_reconhece_a_violacao_de_unicidade_no_postgresql(): void
    {
        $e = $this->consulta('pgsql', '23505', 'SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "propinas_periodo_activo_unique"');

        $this->assertTrue(ViolacaoDeUnicidade::e($e));
    }

    public function test_reconhece_a_violacao_de_unicidade_no_sqlite(): void
    {
        $e = $this->consulta('sqlite', '23000', 'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: propinas.tenant_id, propinas.matricula_id, propinas.periodo_inicio', 19);

        $this->assertTrue(ViolacaoDeUnicidade::e($e));
    }

    public function test_nao_confunde_chave_estrangeira_check_nem_outras_excepcoes(): void
    {
        $this->assertFalse(ViolacaoDeUnicidade::e($this->consulta('pgsql', '23503', 'SQLSTATE[23503]: Foreign key violation: 7 ERROR:  insert or update on table "propinas" violates foreign key constraint "propinas_matricula_id_foreign"')));
        $this->assertFalse(ViolacaoDeUnicidade::e($this->consulta('pgsql', '23514', 'SQLSTATE[23514]: Check violation: 7 ERROR:  new row for relation "propinas" violates check constraint "propinas_valor_positivo_check"')));
        $this->assertFalse(ViolacaoDeUnicidade::e($this->consulta('sqlite', '23000', 'SQLSTATE[23000]: Integrity constraint violation: 19 FOREIGN KEY constraint failed', 19)));
        $this->assertFalse(ViolacaoDeUnicidade::e(new LogicException('UNIQUE constraint failed')));
    }

    public function test_das_propinas_so_aceita_os_indices_unicos_de_propinas(): void
    {
        $this->assertTrue(ViolacaoDeUnicidade::dasPropinas($this->consulta('pgsql', '23505', 'duplicate key value violates unique constraint "propinas_periodo_activo_unique"')));
        $this->assertTrue(ViolacaoDeUnicidade::dasPropinas($this->consulta('pgsql', '23505', 'duplicate key value violates unique constraint "propinas_plano_ordem_activo_unique"')));
        $this->assertTrue(ViolacaoDeUnicidade::dasPropinas($this->consulta('sqlite', '23000', 'UNIQUE constraint failed: propinas.tenant_id, propinas.matricula_id, propinas.periodo_inicio', 19)));
        $this->assertTrue(ViolacaoDeUnicidade::dasPropinas($this->consulta('sqlite', '23000', 'UNIQUE constraint failed: propinas.tenant_id, propinas.matricula_id, propinas.plano_propina_id, propinas.ordem', 19)));
    }

    public function test_das_propinas_reconhece_as_mensagens_reais_do_pdo_pgsql(): void
    {
        foreach (['propinas_plano_ordem_activo_unique', 'propinas_periodo_activo_unique'] as $indice) {
            $mensagem = 'SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "'.$indice.'"'."\nDETAIL:  Key (tenant_id, matricula_id)=(1, 2) already exists.";
            $this->assertTrue(ViolacaoDeUnicidade::dasPropinas($this->consulta('pgsql', '23505', $mensagem)));
        }

        $outra = 'SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "matriculas_numero_unique"';
        $this->assertFalse(ViolacaoDeUnicidade::dasPropinas($this->consulta('pgsql', '23505', $outra)));
    }

    public function test_das_propinas_recusa_outras_violacoes_de_unicidade(): void
    {
        $this->assertFalse(ViolacaoDeUnicidade::dasPropinas($this->consulta('pgsql', '23505', 'duplicate key value violates unique constraint "matriculas_numero_unique"')));
        $this->assertFalse(ViolacaoDeUnicidade::dasPropinas($this->consulta('pgsql', '23505', 'duplicate key value violates unique constraint "propinas_pkey"')));
        $this->assertFalse(ViolacaoDeUnicidade::dasPropinas($this->consulta('sqlite', '23000', 'UNIQUE constraint failed: matriculas.numero_registo_matricula', 19)));
        $this->assertFalse(ViolacaoDeUnicidade::dasPropinas($this->consulta('pgsql', '23503', 'violates foreign key constraint "propinas_periodo_activo_unique"')));
        $this->assertFalse(ViolacaoDeUnicidade::dasPropinas(new LogicException('propinas_periodo_activo_unique')));
    }
}
