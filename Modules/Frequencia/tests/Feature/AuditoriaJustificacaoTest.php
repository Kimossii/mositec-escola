<?php

namespace Modules\Frequencia\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Core\Models\AuditoriaTenant;
use Modules\Frequencia\Actions\AbrirChamadaAction;
use Modules\Frequencia\Actions\JustificarFaltaAction;
use Modules\Frequencia\Actions\RemoverJustificacaoAction;
use Modules\Frequencia\Enums\EstadoPresenca;
use Modules\Frequencia\Models\Registo;
use Modules\Frequencia\Tests\Support\CenarioDeFrequencia;
use Tests\TestCase;

class AuditoriaJustificacaoTest extends TestCase
{
    use CenarioDeFrequencia;
    use RefreshDatabase;

    private array $c;

    private $professor;

    private Registo $falta;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-02 10:00:00');
        $this->c = $this->cenarioDeFrequencia();
        $this->professor = $this->utilizadorDoDocente($this->c['docente']);
        $aula = app(AbrirChamadaAction::class)->executar($this->professor, $this->c['turma']->id, '2026-03-02', $this->c['tempo1']->id);
        $this->falta = $aula->registos->firstWhere('matricula_id', $this->c['alunos'][0]->id);
        $this->falta->update(['estado' => EstadoPresenca::FALTA]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_justificar_regista_motivo_autor_e_entidade(): void
    {
        app(JustificarFaltaAction::class)->executar($this->professor, $this->falta->fresh(), 'Doença', 'Atestado n.º 123');

        $linha = AuditoriaTenant::where('accao', 'falta.justificada')->sole();
        $this->assertSame('frequencia_registos', $linha->entidade_tipo);
        $this->assertSame($this->falta->id, $linha->entidade_id);
        $this->assertSame($this->professor->id, $linha->utilizador_id);
        $this->assertSame(['motivo' => null, 'documento_referencia' => null], $linha->detalhe['antes']);
        $this->assertSame(['motivo' => 'Doença', 'documento_referencia' => 'Atestado n.º 123'], $linha->detalhe['depois']);
    }

    public function test_remover_regista_com_depois_nulo_e_motivo_da_remocao(): void
    {
        app(JustificarFaltaAction::class)->executar($this->professor, $this->falta->fresh(), 'Doença', 'Atestado n.º 123');

        app(RemoverJustificacaoAction::class)->executar($this->professor, $this->falta->fresh(), 'Engano');

        $linha = AuditoriaTenant::where('accao', 'justificacao.removida')->sole();
        $this->assertSame(['motivo' => 'Doença', 'documento_referencia' => 'Atestado n.º 123'], $linha->detalhe['antes']);
        $this->assertNull($linha->detalhe['depois']['motivo']);
        $this->assertSame('Engano', $linha->detalhe['depois']['motivo_remocao']);
    }

    public function test_acesso_recusado_nao_deixa_rasto(): void
    {
        $estranho = $this->criarProfessor('semperm@example.com');
        $estranho->roles()->sync([]);

        try {
            app(JustificarFaltaAction::class)->executar($estranho, $this->falta->fresh(), 'Doença', null);
            $this->fail('Devia recusar.');
        } catch (AuthorizationException) {
        }

        $this->assertSame(0, AuditoriaTenant::count());
    }
}
