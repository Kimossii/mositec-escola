<?php

namespace Modules\Docente\Tests\Feature\Pgsql;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    private int $docentes = 0;

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
        $atributos['docente_id'] ??= $this->criarDocente('Docente ' . ++$this->docentes)->id;

        return AtribuicaoDocente::create(array_merge([
            'ano_lectivo_id' => $cenario['ano']->id,
            'turma_id' => $cenario['turma']->id,
            'disciplina_id' => $cenario['disciplina']->id,
            'papel' => PapelDocente::TITULAR,
            'data_inicio' => '2026-03-01',
        ], $atributos));
    }

    public function test_pg_rejeita_referencias_a_turma_disciplina_ano_ou_docente_de_outro_tenant(): void
    {
        $cenario = $this->cenarioDeDocencia();
        $docente = $this->criarDocente();
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        [$cenarioB, $docenteB] = $this->noTenant($outro, fn () => [$this->cenarioDeDocencia(), $this->criarDocente('Docente B')]);

        $validos = [
            'ano_lectivo_id' => $cenario['ano']->id,
            'turma_id' => $cenario['turma']->id,
            'disciplina_id' => $cenario['disciplina']->id,
            'docente_id' => $docente->id,
        ];
        $deB = [
            'ano_lectivo_id' => $cenarioB['ano']->id,
            'turma_id' => $cenarioB['turma']->id,
            'disciplina_id' => $cenarioB['disciplina']->id,
            'docente_id' => $docenteB->id,
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

        // Outro docente, titular, a sobrepor-se (extremo inclusivo: 31 de Março).
        $this->assertRecusadoPelaBase('23P01', fn () => $this->atribuir($cenario, [
            'docente_id' => $this->criarDocente('Docente outro')->id, 'data_inicio' => '2026-03-31', 'data_fim' => null,
        ]));

        // Sem sobreposição (dia seguinte), outra disciplina, ou substituto: aceites.
        $this->atribuir($cenario, ['docente_id' => $this->criarDocente('Docente seguinte')->id, 'data_inicio' => '2026-04-01']);
        $this->atribuir($cenario, ['docente_id' => $this->criarDocente('Docente mat2')->id, 'disciplina_id' => $cenario['outraDisciplina']->id]);
        $this->atribuir($cenario, [
            'docente_id' => $this->criarDocente('Docente subs')->id, 'papel' => PapelDocente::SUBSTITUTO, 'data_inicio' => '2026-03-10', 'data_fim' => '2026-03-12',
        ]);

        // Uma atribuição eliminada (soft delete) já não conta.
        AtribuicaoDocente::where('data_inicio', '2026-04-01')->firstOrFail()->delete();
        $this->atribuir($cenario, ['docente_id' => $this->criarDocente('Docente reposto')->id, 'data_inicio' => '2026-04-01']);
    }

    public function test_pg_rejeita_mesmo_docente_sobreposto(): void
    {
        $cenario = $this->cenarioDeDocencia();
        $docente = $this->criarDocente();
        $this->atribuir($cenario, ['docente_id' => $docente->id, 'papel' => PapelDocente::SUBSTITUTO, 'data_inicio' => '2026-03-01', 'data_fim' => '2026-03-31']);

        $this->assertRecusadoPelaBase('23P01', fn () => $this->atribuir($cenario, [
            'docente_id' => $docente->id, 'papel' => PapelDocente::TITULAR, 'data_inicio' => '2026-03-31',
        ]));

        $this->atribuir($cenario, ['docente_id' => $docente->id, 'papel' => PapelDocente::TITULAR, 'data_inicio' => '2026-04-01']);
    }

    public function test_pg_fk_composta_rejeita_docente_de_outro_tenant(): void
    {
        $cenario = $this->cenarioDeDocencia();
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $docenteB = $this->noTenant($outro, fn () => $this->criarDocente('Docente B'));

        // Mesmo sem passar pela Action (validador), a base recusa o docente de outro tenant.
        $this->assertRecusadoPelaBase('23503', fn () => $this->atribuir($cenario, ['docente_id' => $docenteB->id]));
        // E um id de docente que não existe.
        $this->assertRecusadoPelaBase('23503', fn () => $this->atribuir($cenario, ['docente_id' => 999999]));
    }

    public function test_pg_constraints_de_exclusao_usam_docente_e_mantem_a_do_titular(): void
    {
        $constraints = collect(DB::select(
            "select conname, pg_get_constraintdef(oid) as def from pg_constraint where conrelid = 'atribuicoes_docentes'::regclass and contype = 'x'"
        ))->pluck('def', 'conname');

        $this->assertEqualsCanonicalizing(['atribuicoes_docentes_um_titular_excl', 'atribuicoes_docentes_docente_excl'], $constraints->keys()->all());
        $this->assertStringContainsString('docente_id WITH =', $constraints['atribuicoes_docentes_docente_excl']);
        $this->assertStringNotContainsString('professor_id', $constraints->implode(' '));
        $this->assertFalse(Schema::hasColumn('atribuicoes_docentes', 'professor_id'));
    }
}
