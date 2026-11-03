<?php

namespace Modules\Avaliacao\Tests\Feature;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Avaliacao\Actions\CriarInstrumentoAction;
use Modules\Avaliacao\Actions\DefinirPrazoAction;
use Modules\Avaliacao\Actions\GuardarRegraAvaliacaoAction;
use Modules\Avaliacao\Actions\LancarNotaAction;
use Modules\Avaliacao\DTO\InstrumentoDTO;
use Modules\Avaliacao\DTO\LancarNotaDTO;
use Modules\Avaliacao\DTO\RegraAvaliacaoDTO;
use Modules\Avaliacao\Enums\EstadoNota;
use Modules\Avaliacao\Enums\ModoArredondamento;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoNota;
use Modules\Avaliacao\Models\AvaliacaoPrazo;
use Modules\Avaliacao\Models\AvaliacaoRegra;
use Modules\Avaliacao\Services\ResultadoDisciplinaService;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Core\Tenancy\Exceptions\AlteracaoDeTenantProibida;
use Modules\Permissao\Enums\Perfil;
use Modules\Tenant\Models\Tenant;
use Tests\TestCase;

class AvaliacaoTenancyTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    private Tenant $outro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
    }

    /** Regra, prazo, instrumento, aluno inscrito e nota, tudo no tenant B. */
    private function dadosDeB(): array
    {
        return $this->noTenant($this->outro, function () {
            $c = $this->cenarioDeAvaliacao('PRI_3', 'B');
            $regra = app(GuardarRegraAvaliacaoAction::class)->executar(new RegraAvaliacaoDTO($c['ano']->id, $c['nivel']->id, 10, '5'));
            $prazo = app(DefinirPrazoAction::class)->executar($regra->id, $c['periodos'][1]->id, TipoInstrumento::MAC, '2026-04-10');
            $instrumento = app(CriarInstrumentoAction::class)->executar(new InstrumentoDTO($c['turma']->id, $c['disciplina']->id, $c['periodos'][1]->id, TipoInstrumento::MAC, 'A1'));
            $inscricao = $this->inscreverAluno($c);
            $nota = AvaliacaoNota::create(['instrumento_id' => $instrumento->id, 'inscricao_disciplina_id' => $inscricao->id, 'valor' => '7', 'estado' => EstadoNota::LANCADA]);

            return compact('c', 'regra', 'prazo', 'instrumento', 'inscricao', 'nota');
        });
    }

    public function test_os_dados_do_tenant_b_nao_aparecem_no_tenant_a(): void
    {
        $b = $this->dadosDeB();

        $this->assertSame(0, AvaliacaoRegra::count());
        $this->assertSame(0, AvaliacaoPrazo::count());
        $this->assertSame(0, AvaliacaoInstrumento::count());
        $this->assertSame(0, AvaliacaoNota::count());
        $this->assertNull(AvaliacaoNota::find($b['nota']->id));
    }

    public function test_as_actions_nao_alcancam_ids_do_tenant_b(): void
    {
        $b = $this->dadosDeB();
        $this->cenarioDeAvaliacao();
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin-a@example.com'));

        try {
            app(LancarNotaAction::class)->executar(new LancarNotaDTO($b['instrumento']->id, $b['inscricao']->id, '9', 'x'));
            $this->fail('Esperava ModelNotFoundException ao lançar numa avaliação de outro tenant.');
        } catch (ModelNotFoundException) {
        }

        try {
            app(ResultadoDisciplinaService::class)->calcular($b['inscricao']->id);
            $this->fail('Esperava ModelNotFoundException ao calcular a inscrição de outro tenant.');
        } catch (ModelNotFoundException) {
        }

        $this->assertSame('7.00', $this->noTenant($this->outro, fn () => AvaliacaoNota::find($b['nota']->id)->valor));
    }

    public function test_a_mini_pauta_de_uma_turma_do_tenant_b_nao_abre_no_tenant_a(): void
    {
        $b = $this->dadosDeB();
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin-a@example.com');

        $this->actingAs($admin)->get('/avaliacao/notas?'.http_build_query(['turma' => $b['c']['turma']->id, 'disciplina' => $b['c']['disciplina']->id]))->assertNotFound();
        $this->actingAs($admin)->post('/avaliacao/notas', ['instrumento_id' => $b['instrumento']->id, 'inscricao_disciplina_id' => $b['inscricao']->id, 'valor' => '9'])->assertNotFound();
        $this->actingAs($admin)->post("/avaliacao/notas/{$b['nota']->id}/anular", ['conta_como_zero' => false, 'motivo' => 'x'])->assertNotFound();
    }

    public function test_um_registo_nao_muda_de_tenant(): void
    {
        $b = $this->dadosDeB();
        $regra = $this->noTenant($this->outro, fn () => AvaliacaoRegra::findOrFail($b['regra']->id));

        $this->expectException(AlteracaoDeTenantProibida::class);
        $regra->update(['modo_arredondamento' => ModoArredondamento::TRUNCAR]);
    }
}
