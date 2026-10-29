<?php

namespace Modules\Docente\Tests\Feature\Pgsql;

use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Services\ValidadorAtribuicaoDocenteService;
use Modules\Docente\Tests\Support\CenarioDeDocencia;
use PHPUnit\Framework\Attributes\Group;
use Tests\PgsqlConcorrenciaTestCase;

/**
 * Duas sessões reais a atribuir titulares à mesma (turma, disciplina) ao mesmo tempo: só uma grava, a outra recebe
 * um erro de validação (nunca uma QueryException). Corre só com a base mositec_escola_test.
 */
#[Group('pgsql')]
class AtribuirDocenteConcorrenciaPgsqlTest extends PgsqlConcorrenciaTestCase
{
    use CenarioDeDocencia;
    use ComProcessosConcorrentesDeDocencia;

    public function test_pg_dois_titulares_em_simultaneo_so_um_e_criado(): void
    {
        $c = $this->cenarioDeDocencia();
        $a = $this->criarDocente('Docente a');
        $b = $this->criarDocente('Docente b');
        $base = ['turma' => $c['turma']->id, 'disciplina' => $c['disciplina']->id, 'papel' => PapelDocente::TITULAR->value];

        $partida = microtime(true) + 2.5;
        $filhos = [
            $this->lancar('atribuir', $base + ['docente' => $a->id, 'inicio' => '2026-03-01', 'fim' => '2026-06-30'], $partida),
            $this->lancar('atribuir', $base + ['docente' => $b->id, 'inicio' => '2026-05-01'], $partida),
        ];
        $respostas = [];
        foreach ($filhos as $i => $filho) {
            $respostas[$i] = $this->esperar($filho);
        }

        $sucessos = array_values(array_filter($respostas, fn (array $r) => $r['ok']));
        $falhas = array_values(array_filter($respostas, fn (array $r) => ! $r['ok']));

        $this->assertCount(1, $sucessos, json_encode($respostas, JSON_UNESCAPED_UNICODE));
        $this->assertCount(1, $falhas, json_encode($respostas, JSON_UNESCAPED_UNICODE));
        $this->assertSame('validacao', $falhas[0]['tipo'], json_encode($falhas[0], JSON_UNESCAPED_UNICODE));
        $this->assertSame(ValidadorAtribuicaoDocenteService::MENSAGEM_TITULAR_SOBREPOSTO, $falhas[0]['mensagem']);
        $this->assertSame(1, AtribuicaoDocente::count());
        $this->assertSame($sucessos[0]['atribuicao'], AtribuicaoDocente::firstOrFail()->id);
    }
}
