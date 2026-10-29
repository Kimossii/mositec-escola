<?php

namespace Modules\Docente\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AnoLectivo\Support\DependenciasRegistadasDoAnoLectivo;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Support\DependenciasDeAtribuicoesDocentes;
use Modules\Docente\Tests\Support\CenarioDeDocencia;
use Tests\TestCase;

class DependenciasDeAtribuicoesDocentesTest extends TestCase
{
    use CenarioDeDocencia;
    use RefreshDatabase;

    private function atribuir(array $c, string $inicio): AtribuicaoDocente
    {
        return AtribuicaoDocente::create([
            'ano_lectivo_id' => $c['ano']->id, 'turma_id' => $c['turma']->id, 'disciplina_id' => $c['disciplina']->id,
            'docente_id' => $this->criarDocente()->id, 'papel' => PapelDocente::TITULAR, 'data_inicio' => $inicio,
        ]);
    }

    public function test_bloqueia_eliminacao_com_atribuicoes(): void
    {
        $c = $this->cenarioDeDocencia();
        $dependencia = app(DependenciasDeAtribuicoesDocentes::class);

        $this->assertNull($dependencia->bloqueiaEliminacao($c['ano']));

        $atribuicao = $this->atribuir($c, '2026-03-01');
        $this->assertStringContainsString('atribuições', (string) $dependencia->bloqueiaEliminacao($c['ano']));
        $this->assertNotNull(app(DependenciasRegistadasDoAnoLectivo::class)->bloqueiaEliminacao($c['ano']));

        $atribuicao->delete();
        $this->assertNull($dependencia->bloqueiaEliminacao($c['ano']));
    }

    public function test_bloqueia_mudanca_de_inicio_que_deixa_atribuicoes_fora(): void
    {
        $c = $this->cenarioDeDocencia();
        $dependencia = app(DependenciasDeAtribuicoesDocentes::class);
        $this->atribuir($c, '2026-03-01');

        // A interface só fornece o modelo: o novo início é o que ele traz (ainda não gravado).
        $c['ano']->data_inicio = '2026-04-01';
        $this->assertStringContainsString('01/03/2026', (string) $dependencia->bloqueiaAlteracaoDeInicio($c['ano']));

        $c['ano']->data_inicio = '2026-03-01';
        $this->assertNull($dependencia->bloqueiaAlteracaoDeInicio($c['ano']));

        $c['ano']->data_inicio = '2026-02-01';
        $this->assertNull($dependencia->bloqueiaAlteracaoDeInicio($c['ano']));
    }

    public function test_esta_registada_com_a_etiqueta_do_ano_lectivo(): void
    {
        $classes = collect(app()->tagged(DependenciasRegistadasDoAnoLectivo::ETIQUETA))->map(fn ($d) => $d::class);

        $this->assertContains(DependenciasDeAtribuicoesDocentes::class, $classes->all());
    }
}
