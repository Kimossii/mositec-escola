<?php

namespace Modules\Frequencia\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Frequencia\Actions\AbrirChamadaAction;
use Modules\Frequencia\Actions\MarcarNaoLeccionadaAction;
use Modules\Frequencia\Actions\ReabrirAulaAction;
use Modules\Frequencia\Enums\EstadoAula;
use Modules\Frequencia\Tests\Support\CenarioDeFrequencia;
use Tests\TestCase;

class MarcarNaoLeccionadaActionTest extends TestCase
{
    use CenarioDeFrequencia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-02 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_exige_motivo_e_muda_estado(): void
    {
        $c = $this->cenarioDeFrequencia();
        $u = $this->utilizadorDoDocente($c['docente']);
        $aula = app(AbrirChamadaAction::class)->executar($u, $c['turma']->id, '2026-03-02', $c['tempo1']->id);

        try {
            app(MarcarNaoLeccionadaAction::class)->executar($u, $aula, '   ');
            $this->fail('Esperava recusa sem motivo.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('motivo', $e->errors());
        }

        $r = app(MarcarNaoLeccionadaAction::class)->executar($u, $aula, 'Greve de professores');

        $this->assertSame(EstadoAula::NAO_LECCIONADA, $r->estado);
        $this->assertSame('Greve de professores', $r->motivo_nao_leccionada);
        $this->assertSame('Não leccionada', $r->fresh()->estado_descricao);
    }

    public function test_so_quem_pode_marcar_a_aula(): void
    {
        $c = $this->cenarioDeFrequencia();
        $aula = app(AbrirChamadaAction::class)->executar($this->utilizadorDoDocente($c['docente']), $c['turma']->id, '2026-03-02', $c['tempo1']->id);
        $intruso = $this->criarDocente('Intruso', comAcesso: true)->utilizador();

        $this->expectException(AuthorizationException::class);

        app(MarcarNaoLeccionadaAction::class)->executar($intruso, $aula, 'x');
    }

    public function test_reabrir_devolve_a_aula_a_realizada_e_so_quem_pode_marcar(): void
    {
        $c = $this->cenarioDeFrequencia();
        $u = $this->utilizadorDoDocente($c['docente']);
        $aula = app(AbrirChamadaAction::class)->executar($u, $c['turma']->id, '2026-03-02', $c['tempo1']->id);
        app(MarcarNaoLeccionadaAction::class)->executar($u, $aula, 'Clicou por engano');

        $reaberta = app(ReabrirAulaAction::class)->executar($u, $aula->fresh());

        $this->assertSame(EstadoAula::REALIZADA, $reaberta->estado);
        $this->assertNull($reaberta->motivo_nao_leccionada);
        $this->assertSame('Realizada', $reaberta->fresh()->estado_descricao);

        try {
            app(ReabrirAulaAction::class)->executar($u, $aula->fresh());
            $this->fail('Esperava recusa: a aula já está realizada.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('aula', $e->errors());
        }

        $intruso = $this->criarDocente('Intruso', comAcesso: true)->utilizador();
        app(MarcarNaoLeccionadaAction::class)->executar($u, $aula->fresh(), 'Greve');
        $this->expectException(AuthorizationException::class);
        app(ReabrirAulaAction::class)->executar($intruso, $aula->fresh());
    }
}
