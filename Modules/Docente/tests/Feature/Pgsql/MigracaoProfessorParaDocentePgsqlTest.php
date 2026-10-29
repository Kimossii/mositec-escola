<?php

namespace Modules\Docente\Tests\Feature\Pgsql;

use Illuminate\Support\Facades\DB;
use Modules\Docente\Tests\Support\CenarioDeDocencia;
use Modules\Docente\Tests\Support\VerificaMigracaoProfessorParaDocente;
use PHPUnit\Framework\Attributes\Group;
use Tests\PgsqlTestCase;

/**
 * Migração `professor_id` → `docente_id` em PostgreSQL: os mesmos casos da versão SQLite e, além disso, a troca
 * das constraints de exclusão nos dois sentidos. Corre só com a base mositec_escola_test.
 */
#[Group('pgsql')]
class MigracaoProfessorParaDocentePgsqlTest extends PgsqlTestCase
{
    use CenarioDeDocencia;
    use VerificaMigracaoProfessorParaDocente;

    /** @return list<string> */
    private function exclusoes(): array
    {
        return collect(DB::select("select conname from pg_constraint where conrelid = 'atribuicoes_docentes'::regclass and contype = 'x' order by conname"))
            ->pluck('conname')->all();
    }

    public function test_pg_migracao_troca_a_exclusao_do_professor_pela_do_docente(): void
    {
        $this->assertSame(['atribuicoes_docentes_docente_excl', 'atribuicoes_docentes_um_titular_excl'], $this->exclusoes());

        $this->migracao()->down();
        $this->assertSame(['atribuicoes_docentes_professor_excl', 'atribuicoes_docentes_um_titular_excl'], $this->exclusoes());

        $this->migracao()->up();
        $this->assertSame(['atribuicoes_docentes_docente_excl', 'atribuicoes_docentes_um_titular_excl'], $this->exclusoes());
    }
}
