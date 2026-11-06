<?php

namespace Modules\Avaliacao\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Avaliacao\Actions\AprovarPautaAction;
use Modules\Avaliacao\Actions\LancarCompetenciaAction;
use Modules\Avaliacao\Actions\LancarQualitativaAction;
use Modules\Avaliacao\DTO\LancarCompetenciaDTO;
use Modules\Avaliacao\DTO\LancarQualitativaDTO;
use Modules\Avaliacao\Enums\Decisao;
use Modules\Avaliacao\Enums\Progressao;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoCompetencia;
use Modules\Avaliacao\Models\AvaliacaoDecisao;
use Modules\Avaliacao\Models\AvaliacaoEscala;
use Modules\Avaliacao\Models\AvaliacaoProgressao;
use Modules\Avaliacao\Models\AvaliacaoRegraQualitativa;
use Modules\Avaliacao\Services\PautaFinalService;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Permissao\Enums\Perfil;
use Tests\TestCase;

class ProgressaoEHistoricoTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    private array $c;

    private AvaliacaoEscala $escala;

    private InscricaoDisciplina $inscricao;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->c, $this->escala] = $this->cenarioQualitativo();
        $this->inscricao = $this->inscreverAluno($this->c, 'Rita');
        $this->travelTo('2026-03-15 10:00:00');
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com'));
    }

    private function manual(): void
    {
        $this->guardarRegraQualitativa($this->c['ano']->id, $this->c['nivel']->id, $this->escala->id, Progressao::MANUAL);
    }

    private function avaliarTrimestres(): void
    {
        foreach ([1 => 'B', 2 => 'B', 3 => 'MB'] as $n => $codigo) {
            app(LancarQualitativaAction::class)->executar(new LancarQualitativaDTO($this->inscricao->id, $this->c['periodos'][$n]->id, $this->escala->niveis->firstWhere('codigo', $codigo)->id));
        }
    }

    private function pauta(): array
    {
        return app(PautaFinalService::class)->paraTurma($this->c['turma']->id);
    }

    private function decidir(Decisao $d, ?string $motivo = null): AvaliacaoProgressao
    {
        return $this->decidirProgressao($this->inscricao->matricula_id, $d, $motivo);
    }

    // ---- progressão

    public function test_a_progressao_automatica_e_o_padrao_e_nao_se_decide_crianca_a_crianca(): void
    {
        $this->avaliarTrimestres();

        $this->assertSame(Progressao::AUTOMATICA, AvaliacaoRegraQualitativa::firstOrFail()->progressao);
        $this->assertSame('Transita', $this->pauta()['alunos'][0]['decisao']['rotulo']);
        $this->assertFalse($this->pauta()['progressao_manual']);

        $this->expectException(ValidationException::class);
        $this->decidir(Decisao::TRANSITA);
    }

    public function test_na_manual_a_pauta_espera_a_decisao_e_so_se_aprova_depois_dela(): void
    {
        $this->manual();
        $this->avaliarTrimestres();

        $p = $this->pauta();
        $this->assertTrue($p['progressao_manual']);
        $this->assertNull($p['alunos'][0]['decisao']);
        $this->assertFalse($p['pode_aprovar']);
        $this->assertStringContainsString('Falta decidir a progressão de 1 criança', implode(' ', $p['bloqueios']));

        $this->decidir(Decisao::TRANSITA);
        $p = $this->pauta();
        $this->assertTrue($p['pode_aprovar']);

        app(AprovarPautaAction::class)->executar($this->c['turma']->id);
        $this->assertSame(Decisao::TRANSITA, AvaliacaoDecisao::firstOrFail()->decisao);
        $this->assertSame('Transita', $this->pauta()['alunos'][0]['decisao']['rotulo']);
    }

    public function test_nao_transita_exige_motivo_e_fica_na_pauta_definitiva(): void
    {
        $this->manual();
        $this->avaliarTrimestres();

        try {
            $this->decidir(Decisao::NAO_TRANSITA, '  ');
            $this->fail('Esperava ValidationException (motivo).');
        } catch (ValidationException) {
        }

        $this->decidir(Decisao::NAO_TRANSITA, 'Falta de maturidade, a pedido do encarregado');
        $this->assertSame('Falta de maturidade, a pedido do encarregado', $this->pauta()['alunos'][0]['motivo_progressao']);

        app(AprovarPautaAction::class)->executar($this->c['turma']->id);
        $this->assertSame(Decisao::NAO_TRANSITA, AvaliacaoDecisao::firstOrFail()->decisao);
    }

    public function test_decidir_de_novo_actualiza_e_apto_nao_serve_no_pre_escolar(): void
    {
        $this->manual();
        $this->decidir(Decisao::NAO_TRANSITA, 'Motivo');
        $this->decidir(Decisao::TRANSITA);

        $this->assertSame(1, AvaliacaoProgressao::count());
        $this->assertSame(Decisao::TRANSITA, AvaliacaoProgressao::firstOrFail()->decisao);
        $this->assertNull(AvaliacaoProgressao::firstOrFail()->motivo);

        $this->expectException(ValidationException::class);
        $this->decidir(Decisao::APTO);
    }

    public function test_so_quem_aprova_decide_e_com_a_pauta_definitiva_ja_nao_se_altera(): void
    {
        $this->manual();
        $this->avaliarTrimestres();
        $this->decidir(Decisao::TRANSITA);
        app(AprovarPautaAction::class)->executar($this->c['turma']->id);

        try {
            $this->decidir(Decisao::NAO_TRANSITA, 'Tarde demais');
            $this->fail('Pauta definitiva.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('pauta', $e->errors());
        }

        $this->actingAs($this->professorAtribuido($this->c));
        $this->expectException(AuthorizationException::class);
        $this->decidir(Decisao::TRANSITA);
    }

    public function test_decidir_via_http_valida_e_respeita_a_turma_e_as_permissoes(): void
    {
        $this->manual();
        $url = "/avaliacao/pautas/{$this->c['turma']->id}/progressao";
        $matricula = $this->inscricao->matricula_id;

        $this->post($url, ['matricula_id' => $matricula, 'decisao' => 0])->assertRedirect();
        $this->assertSame(1, AvaliacaoProgressao::count());
        $this->post($url, ['matricula_id' => $matricula, 'decisao' => 1])->assertSessionHasErrors('motivo');
        $this->post($url, ['matricula_id' => $matricula, 'decisao' => 5])->assertSessionHasErrors('decisao');

        $outra = $this->cenarioDeAvaliacao('PRE_II', 'Y');
        $deOutraTurma = $this->inscreverAluno($outra);
        $this->post($url, ['matricula_id' => $deOutraTurma->matricula_id, 'decisao' => 0])->assertNotFound();

        $this->actingAs($this->professorAtribuido($this->c, 'prof@example.com'))->post($url, ['matricula_id' => $matricula, 'decisao' => 0])->assertForbidden();
    }

    public function test_a_configuracao_guarda_a_progressao_da_classe(): void
    {
        $this->post('/avaliacao/configuracao/regras-qualitativas', [
            'ano_lectivo_id' => $this->c['ano']->id, 'nivel_academico_id' => $this->c['nivel']->id, 'escala_id' => $this->escala->id, 'progressao' => Progressao::MANUAL->value,
        ])->assertRedirect();

        $this->get('/avaliacao/configuracao')->assertInertia(fn (Assert $p) => $p
            ->where('classes.0.regra_qualitativa.progressao', 1)->has('progressoes', 2));
    }

    public function test_a_progressao_do_tenant_b_e_inalcancavel(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $b = $this->noTenant($outro, function () {
            [$c, $escala] = $this->cenarioQualitativo('B');
            $this->guardarRegraQualitativa($c['ano']->id, $c['nivel']->id, $escala->id, Progressao::MANUAL);

            return ['c' => $c, 'inscricao' => $this->inscreverAluno($c)];
        });

        $this->post("/avaliacao/pautas/{$b['c']['turma']->id}/progressao", ['matricula_id' => $b['inscricao']->matricula_id, 'decisao' => 0])->assertNotFound();
        $this->assertSame(0, AvaliacaoProgressao::count());
    }

    // ---- histórico das competências

    public function test_cada_alteracao_de_competencia_fica_no_historico_e_repetir_nao_acrescenta(): void
    {
        $this->guardarCompetencias($this->c['disciplina']->id, null, [['descricao' => 'Conta até 5']]);
        $competencia = AvaliacaoCompetencia::firstOrFail();
        $lancar = fn (string $codigo, ?string $motivo = null) => app(LancarCompetenciaAction::class)->executar(new LancarCompetenciaDTO(
            $this->inscricao->id, $competencia->id, $this->c['periodos'][1]->id, $this->escala->niveis->firstWhere('codigo', $codigo)->id, $motivo,
        ));

        $registo = $lancar('B');
        $lancar('MB');
        $lancar('MB');

        $h = $registo->historico()->orderBy('id')->get();
        $this->assertCount(2, $h);
        $this->assertNull($h[0]->escala_nivel_anterior_id);
        $this->assertSame($this->escala->niveis->firstWhere('codigo', 'B')->id, $h[1]->escala_nivel_anterior_id);
        $this->assertSame($this->escala->niveis->firstWhere('codigo', 'MB')->id, $h[1]->escala_nivel_novo_id);
        $this->assertFalse($h[1]->fora_do_prazo);
    }

    public function test_o_historico_marca_o_que_foi_feito_fora_do_prazo(): void
    {
        $this->guardarCompetencias($this->c['disciplina']->id, null, [['descricao' => 'Conta até 5']]);
        $this->definirPrazo(AvaliacaoRegraQualitativa::firstOrFail()->id, $this->c['periodos'][1]->id, TipoInstrumento::QUALITATIVA, '2026-03-01');

        $registo = app(LancarCompetenciaAction::class)->executar(new LancarCompetenciaDTO(
            $this->inscricao->id, AvaliacaoCompetencia::firstOrFail()->id, $this->c['periodos'][1]->id, $this->escala->niveis[0]->id, 'Entregue em atraso',
        ));

        $h = $registo->historico()->firstOrFail();
        $this->assertTrue($h->fora_do_prazo);
        $this->assertSame('Entregue em atraso', $h->motivo);
        $this->assertNotNull($h->criado_por);
    }
}
