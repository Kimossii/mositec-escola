<?php

namespace Modules\Avaliacao\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Avaliacao\Actions\CriarInstrumentoAction;
use Modules\Avaliacao\Actions\LancarQualitativaAction;
use Modules\Avaliacao\DTO\InstrumentoDTO;
use Modules\Avaliacao\DTO\LancarQualitativaDTO;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoEscala;
use Modules\Avaliacao\Models\AvaliacaoPrazo;
use Modules\Avaliacao\Models\AvaliacaoQualitativa;
use Modules\Avaliacao\Models\AvaliacaoRegraQualitativa;
use Modules\Avaliacao\Services\PrazoService;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Permissao\Enums\Perfil;
use Tests\TestCase;

class PrazoQualitativoTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    private array $c;

    private AvaliacaoEscala $escala;

    private AvaliacaoRegraQualitativa $regra;

    private InscricaoDisciplina $inscricao;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->c, $this->escala] = $this->cenarioQualitativo();
        $this->regra = AvaliacaoRegraQualitativa::firstOrFail();
        $this->inscricao = $this->inscreverAluno($this->c, 'Ana');
        $this->travelTo('2026-03-15 10:00:00');
    }

    private function prazo(string $data): AvaliacaoPrazo
    {
        return $this->definirPrazo($this->regra->id, $this->c['periodos'][1]->id, TipoInstrumento::QUALITATIVA, $data);
    }

    private function lancar(string $codigo = 'B', ?string $motivo = null): AvaliacaoQualitativa
    {
        return app(LancarQualitativaAction::class)->executar(new LancarQualitativaDTO($this->inscricao->id, $this->c['periodos'][1]->id, $this->escala->niveis->firstWhere('codigo', $codigo)->id, null, $motivo));
    }

    private function limite(?int $docenteId = null): string
    {
        return app(PrazoService::class)->dataLimite($this->regra, $this->c['periodos'][1]->id, TipoInstrumento::QUALITATIVA, $this->c['turma']->id, $this->c['disciplina']->id, $docenteId)->toDateString();
    }

    public function test_o_prazo_fica_na_regra_qualitativa_e_vence_o_fim_do_trimestre(): void
    {
        $this->assertSame('2026-04-30', $this->limite());

        $p = $this->prazo('2026-04-10');

        $this->assertSame($this->regra->id, $p->regra_qualitativa_id);
        $this->assertNull($p->regra_id);
        $this->assertSame('2026-04-10', $this->limite());

        $this->prazo('2026-04-12');
        $this->assertSame(1, AvaliacaoPrazo::count());
        $this->assertSame('2026-04-12', $this->limite());
    }

    public function test_prazo_terminado_so_deixa_lancar_quem_tem_permissao_e_com_motivo(): void
    {
        $this->prazo('2026-03-01');
        $this->actingAs($this->professorAtribuido($this->c));

        try {
            $this->lancar();
            $this->fail('O professor não lança depois do prazo da escola.');
        } catch (AuthorizationException) {
        }

        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com'));
        try {
            $this->lancar();
            $this->fail('Esperava ValidationException (motivo).');
        } catch (ValidationException) {
        }

        $this->lancar('B', 'Avaliação entregue em atraso');
        $this->assertTrue(AvaliacaoQualitativa::firstOrFail()->historico()->firstOrFail()->fora_do_prazo);
    }

    public function test_prolongar_o_prazo_de_uma_turma_reabre_o_lancamento_ao_professor(): void
    {
        $prazo = $this->prazo('2026-03-01');
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com'));
        $this->prolongarPrazo($prazo->id, '2026-03-31', 'Educadora de baixa', turmaId: $this->c['turma']->id);

        $this->assertSame('2026-03-31', $this->limite());

        $this->actingAs($this->professorAtribuido($this->c));
        $this->assertSame('Bom', $this->lancar('B')->nivel->designacao);
    }

    public function test_prazo_so_aceita_trimestres_do_ano_da_regra(): void
    {
        $outro = $this->cenarioDeAvaliacao('PRI_3', 'Z');

        $this->expectException(ValidationException::class);
        $this->definirPrazo($this->regra->id, $outro['periodos'][1]->id, TipoInstrumento::QUALITATIVA, '2026-04-10');
    }

    public function test_a_avaliacao_qualitativa_nao_se_cria_como_avaliacao_manual(): void
    {
        $this->expectException(ValidationException::class);
        app(CriarInstrumentoAction::class)->executar(new InstrumentoDTO($this->c['turma']->id, $this->c['disciplina']->id, $this->c['periodos'][1]->id, TipoInstrumento::QUALITATIVA, 'X'));
    }

    public function test_configuracao_mostra_e_define_o_prazo_da_classe_qualitativa_via_http(): void
    {
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com');

        $this->actingAs($admin)->post('/avaliacao/configuracao/prazos', [
            'regra_id' => $this->regra->id, 'periodo_id' => $this->c['periodos'][1]->id, 'tipo' => TipoInstrumento::QUALITATIVA->value, 'data_limite' => '2026-04-10',
        ])->assertRedirect();

        $this->actingAs($admin)->get('/avaliacao/configuracao')->assertInertia(fn (Assert $p) => $p
            ->where('classes.0.regra_qualitativa.id', $this->regra->id)
            ->where('classes.0.prazos.0.tipo', TipoInstrumento::QUALITATIVA->value)
            ->where('classes.0.prazos.0.data_limite', '2026-04-10'));

        $prazoId = AvaliacaoPrazo::firstOrFail()->id;
        $this->actingAs($admin)->post("/avaliacao/configuracao/prazos/{$prazoId}/excepcoes", ['data_limite' => '2026-04-20', 'motivo' => 'Greve', 'turma_id' => $this->c['turma']->id])->assertRedirect();
        $this->assertSame('2026-04-20', $this->limite());
    }

    public function test_a_mini_pauta_qualitativa_mostra_o_prazo_configurado(): void
    {
        $this->prazo('2026-03-01');
        $url = '/avaliacao/notas?'.http_build_query(['turma' => $this->c['turma']->id, 'disciplina' => $this->c['disciplina']->id, 'periodo' => $this->c['periodos'][1]->id]);

        $this->actingAs($this->professorAtribuido($this->c))->get($url)->assertInertia(fn (Assert $p) => $p
            ->where('pauta.prazo.data_limite', '2026-03-01')
            ->where('pauta.prazo.encerrado', true));
    }
}
