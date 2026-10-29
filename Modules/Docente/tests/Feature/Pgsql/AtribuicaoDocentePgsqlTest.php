<?php

namespace Modules\Docente\Tests\Feature\Pgsql;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Tests\Support\CenarioDeDocencia;
use PHPUnit\Framework\Attributes\Group;
use Tests\PgsqlTestCase;

/**
 * O que só o PostgreSQL prova nas atribuições docentes: FK compostas por tenant e constraints de exclusão.
 * Corre só com a base mositec_escola_test (ver tests/PgsqlTestCase.php).
 */
#[Group('pgsql')]
class AtribuicaoDocentePgsqlTest extends PgsqlTestCase
{
    use CenarioDeDocencia;

    private int $professores = 0;

    /** Savepoint: no PostgreSQL um erro aborta a transacção do teste inteira. */
    private function assertRecusadoPelaBase(string $sqlstate, Closure $operacao): void
    {
        try {
            DB::transaction($operacao);
        } catch (QueryException $e) {
            $this->assertSame($sqlstate, $e->errorInfo[0] ?? null, $e->getMessage());

            return;
        }

        $this->fail("A base de dados devia ter recusado a operação ({$sqlstate}).");
    }

    private function atribuir(array $cenario, array $atributos = []): AtribuicaoDocente
    {
        $atributos['professor_id'] ??= $this->criarProfessor('p' . ++$this->professores . '@example.com')->id;

        return AtribuicaoDocente::create(array_merge([
            'ano_lectivo_id' => $cenario['ano']->id,
            'turma_id' => $cenario['turma']->id,
            'disciplina_id' => $cenario['disciplina']->id,
            'papel' => PapelDocente::TITULAR,
            'data_inicio' => '2026-03-01',
        ], $atributos));
    }

    public function test_pg_rejeita_referencias_a_turma_disciplina_ano_ou_professor_de_outro_tenant(): void
    {
        $cenario = $this->cenarioDeDocencia();
        $professor = $this->criarProfessor();
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        [$cenarioB, $professorB] = $this->noTenant($outro, fn () => [$this->cenarioDeDocencia(), $this->criarProfessor('prof-b@example.com')]);

        $validos = [
            'ano_lectivo_id' => $cenario['ano']->id,
            'turma_id' => $cenario['turma']->id,
            'disciplina_id' => $cenario['disciplina']->id,
            'professor_id' => $professor->id,
        ];
        $deB = [
            'ano_lectivo_id' => $cenarioB['ano']->id,
            'turma_id' => $cenarioB['turma']->id,
            'disciplina_id' => $cenarioB['disciplina']->id,
            'professor_id' => $professorB->id,
        ];

        // O tenant da linha é o do contexto (A): cada referência de B tem de ser recusada (FK composta).
        foreach ($deB as $coluna => $idDeB) {
            $this->assertRecusadoPelaBase('23503', fn () => $this->atribuir($cenario, array_merge($validos, [$coluna => $idDeB])));
        }

        // Com referências do próprio tenant é aceite.
        $this->assertNotNull($this->atribuir($cenario, $validos)->id);
    }

    public function test_pg_rejeita_dois_titulares_sobrepostos_mesmo_sem_passar_pela_action(): void
    {
        $cenario = $this->cenarioDeDocencia();
        $this->atribuir($cenario, ['data_inicio' => '2026-03-01', 'data_fim' => '2026-03-31']);

        // Outro professor, titular, a sobrepor-se (extremo inclusivo: 31 de Março).
        $this->assertRecusadoPelaBase('23P01', fn () => $this->atribuir($cenario, [
            'professor_id' => $this->criarProfessor('outro@example.com')->id, 'data_inicio' => '2026-03-31', 'data_fim' => null,
        ]));

        // Sem sobreposição (dia seguinte), outra disciplina, ou substituto: aceites.
        $this->atribuir($cenario, ['professor_id' => $this->criarProfessor('seguinte@example.com')->id, 'data_inicio' => '2026-04-01']);
        $this->atribuir($cenario, ['professor_id' => $this->criarProfessor('mat2@example.com')->id, 'disciplina_id' => $cenario['outraDisciplina']->id]);
        $this->atribuir($cenario, [
            'professor_id' => $this->criarProfessor('subs@example.com')->id, 'papel' => PapelDocente::SUBSTITUTO, 'data_inicio' => '2026-03-10', 'data_fim' => '2026-03-12',
        ]);

        // Uma atribuição eliminada (soft delete) já não conta.
        AtribuicaoDocente::where('data_inicio', '2026-04-01')->firstOrFail()->delete();
        $this->atribuir($cenario, ['professor_id' => $this->criarProfessor('reposto@example.com')->id, 'data_inicio' => '2026-04-01']);
    }

    public function test_pg_rejeita_o_mesmo_professor_sobreposto_na_mesma_turma_e_disciplina(): void
    {
        $cenario = $this->cenarioDeDocencia();
        $professor = $this->criarProfessor();
        $this->atribuir($cenario, ['professor_id' => $professor->id, 'papel' => PapelDocente::SUBSTITUTO, 'data_inicio' => '2026-03-01', 'data_fim' => '2026-03-31']);

        $this->assertRecusadoPelaBase('23P01', fn () => $this->atribuir($cenario, [
            'professor_id' => $professor->id, 'papel' => PapelDocente::TITULAR, 'data_inicio' => '2026-03-31',
        ]));

        $this->atribuir($cenario, ['professor_id' => $professor->id, 'papel' => PapelDocente::TITULAR, 'data_inicio' => '2026-04-01']);
    }
}
