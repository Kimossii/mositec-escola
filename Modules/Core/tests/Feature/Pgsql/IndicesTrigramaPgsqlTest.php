<?php

namespace Modules\Core\Tests\Feature\Pgsql;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\PgsqlTestCase;

#[Group('pgsql')]
class IndicesTrigramaPgsqlTest extends PgsqlTestCase
{
    public function test_as_colunas_mais_pesquisadas_com_contem_tem_indice_de_trigramas(): void
    {
        $indices = DB::table('pg_indexes')->whereIn('indexname', [
            'dados_pessoas_nome_completo_trgm', 'alunos_numero_matricula_trgm', 'matriculas_numero_registo_trgm',
        ])->pluck('indexdef', 'indexname');

        $this->assertCount(3, $indices);
        $this->assertStringContainsString('gin_trgm_ops', (string) $indices['dados_pessoas_nome_completo_trgm']);
    }
}
