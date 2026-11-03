<?php

namespace Modules\Avaliacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Avaliacao\Actions\GuardarRegraAvaliacaoAction;
use Modules\Avaliacao\DTO\RegraAvaliacaoDTO;
use Modules\Avaliacao\Enums\EstadoPauta;
use Modules\Avaliacao\Models\AvaliacaoPauta;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Permissao\Enums\Perfil;
use Tests\TestCase;

class PautaHttpTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    private array $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->c = $this->cenarioDeAvaliacao();
        app(GuardarRegraAvaliacaoAction::class)->executar(new RegraAvaliacaoDTO($this->c['ano']->id, $this->c['nivel']->id, 10, '5'));
        $this->notasCompletas($this->c, $this->inscreverAluno($this->c, 'Ana'), [1 => ['5.33', '7'], 2 => ['6', '7'], 3 => ['8', '9']]);
    }

    private function admin()
    {
        return $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com');
    }

    private function url(): string
    {
        return '/avaliacao/pautas?turma='.$this->c['turma']->id;
    }

    public function test_admin_ve_a_pauta_provisoria_com_decisao_e_mfd(): void
    {
        $this->actingAs($this->admin())->get($this->url())->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Avaliacao/Pautas/Pauta')
            ->has('turmas', 1)
            ->where('pauta.estado', 'provisoria')
            ->where('pauta.pode_aprovar', true)
            ->where('pauta.alunos.0.decisao.rotulo', 'Transita')
            ->where("pauta.alunos.0.celulas.{$this->c['disciplina']->id}.mfd", '7.06')
            ->where('pode.aprovar', true));
    }

    public function test_professor_nao_ve_a_pauta_da_turma_inteira(): void
    {
        $this->actingAs($this->professorAtribuido($this->c))->get($this->url())->assertForbidden();
    }

    public function test_aprovar_e_reabrir_via_http(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post("/avaliacao/pautas/{$this->c['turma']->id}/aprovar")->assertRedirect();
        $this->assertSame(EstadoPauta::DEFINITIVA, AvaliacaoPauta::firstOrFail()->estado);

        $this->actingAs($admin)->post("/avaliacao/pautas/{$this->c['turma']->id}/reabrir", ['motivo' => ''])->assertSessionHasErrors('motivo');
        $this->actingAs($admin)->post("/avaliacao/pautas/{$this->c['turma']->id}/reabrir", ['motivo' => 'Erro de lançamento'])->assertRedirect();
        $this->assertSame(EstadoPauta::REABERTA, AvaliacaoPauta::firstOrFail()->estado);
    }

    public function test_sem_permissao_nao_aprova_nem_reabre(): void
    {
        $professor = $this->professorAtribuido($this->c);

        $this->actingAs($professor)->post("/avaliacao/pautas/{$this->c['turma']->id}/aprovar")->assertForbidden();
        $this->actingAs($professor)->post("/avaliacao/pautas/{$this->c['turma']->id}/reabrir", ['motivo' => 'x'])->assertForbidden();
        $this->assertSame(0, AvaliacaoPauta::count());
    }

    public function test_pauta_com_pendentes_volta_com_erro_ao_aprovar(): void
    {
        $this->inscreverAluno($this->c, 'Sem notas');

        $this->actingAs($this->admin())->post("/avaliacao/pautas/{$this->c['turma']->id}/aprovar")->assertSessionHasErrors('pauta');
        $this->assertSame(0, AvaliacaoPauta::count());
    }
}
