<?php

namespace Modules\Avaliacao\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\Actions\AnularNotaAction;
use Modules\Avaliacao\Actions\CriarInstrumentoAction;
use Modules\Avaliacao\Actions\DefinirPrazoAction;
use Modules\Avaliacao\Actions\GuardarRegraAvaliacaoAction;
use Modules\Avaliacao\Actions\LancarNotaAction;
use Modules\Avaliacao\DTO\InstrumentoDTO;
use Modules\Avaliacao\DTO\LancarNotaDTO;
use Modules\Avaliacao\DTO\RegraAvaliacaoDTO;
use Modules\Avaliacao\Enums\EstadoNota;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoNota;
use Modules\Avaliacao\Models\AvaliacaoNotaHistorico;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Permissao\Enums\Perfil;
use Tests\TestCase;

class LancarNotaActionTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    private array $c;

    private AvaliacaoInstrumento $instrumento;

    private InscricaoDisciplina $inscricao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->c = $this->cenarioDeAvaliacao();

        $regra = app(GuardarRegraAvaliacaoAction::class)->executar(new RegraAvaliacaoDTO(
            ano_lectivo_id: $this->c['ano']->id, nivel_academico_id: $this->c['nivel']->id, escala_maxima: 10, nota_minima: '5',
        ));
        app(DefinirPrazoAction::class)->executar($regra->id, $this->c['periodos'][1]->id, TipoInstrumento::MAC, '2026-04-10');

        $this->instrumento = app(CriarInstrumentoAction::class)->executar(
            new InstrumentoDTO($this->c['turma']->id, $this->c['disciplina']->id, $this->c['periodos'][1]->id, TipoInstrumento::MAC, 'Avaliação 1'),
        );
        $this->inscricao = $this->inscreverAluno($this->c);
        $this->travelTo('2026-03-15 10:00:00');
    }

    private function lancar(string $valor, ?string $motivo = null, ?InscricaoDisciplina $inscricao = null): AvaliacaoNota
    {
        return app(LancarNotaAction::class)->executar(new LancarNotaDTO($this->instrumento->id, ($inscricao ?? $this->inscricao)->id, $valor, $motivo));
    }

    public function test_docente_atribuido_lanca_dentro_do_prazo(): void
    {
        $this->actingAs($this->professorAtribuido($this->c));

        $nota = $this->lancar('7.5');

        $this->assertSame('7.50', $nota->valor);
        $this->assertSame(EstadoNota::LANCADA, $nota->estado);
        $this->assertFalse(AvaliacaoNotaHistorico::first()->fora_do_prazo);
    }

    public function test_docente_nao_atribuido_nao_lanca(): void
    {
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::PROFESSOR, 'outro@example.com'));

        $this->expectException(AuthorizationException::class);
        $this->lancar('7');
    }

    public function test_nota_zero_e_valida(): void
    {
        $this->actingAs($this->professorAtribuido($this->c));

        $nota = $this->lancar('0');

        $this->assertSame('0.00', $nota->valor);
        $this->assertSame(EstadoNota::LANCADA, $nota->estado);
    }

    public function test_valores_fora_da_escala_ou_mal_formados_sao_rejeitados(): void
    {
        $this->actingAs($this->professorAtribuido($this->c));

        foreach (['10.01', '11', '-1', 'abc', '', '7,5'] as $invalido) {
            try {
                $this->lancar($invalido);
                $this->fail("Esperava ValidationException para '{$invalido}'.");
            } catch (ValidationException) {
            }
        }

        $this->assertSame(0, AvaliacaoNota::count());
        $this->assertSame('10.00', $this->lancar('10')->valor);
    }

    public function test_depois_do_prazo_sem_permissao_e_recusado(): void
    {
        $this->actingAs($this->professorAtribuido($this->c));
        $this->travelTo('2026-04-20 10:00:00');

        $this->expectException(AuthorizationException::class);
        $this->lancar('7');
    }

    public function test_depois_do_prazo_com_permissao_exige_motivo_e_grava_historico_fora_do_prazo(): void
    {
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com'));
        $this->travelTo('2026-04-20 10:00:00');

        try {
            $this->lancar('6');
            $this->fail('Esperava ValidationException (motivo).');
        } catch (ValidationException) {
        }

        $this->lancar('6', 'Pauta entregue em atraso');

        $h = AvaliacaoNotaHistorico::first();
        $this->assertTrue($h->fora_do_prazo);
        $this->assertSame('Pauta entregue em atraso', $h->motivo);
    }

    public function test_alterar_nota_grava_valor_anterior_no_historico(): void
    {
        $this->actingAs($this->professorAtribuido($this->c));

        $this->lancar('5');
        $this->lancar('8');
        $this->lancar('8');

        $historico = AvaliacaoNotaHistorico::orderBy('id')->get();
        $this->assertCount(2, $historico);
        $this->assertNull($historico[0]->valor_anterior);
        $this->assertSame('5.00', $historico[1]->valor_anterior);
        $this->assertSame('8.00', $historico[1]->valor_novo);
        $this->assertSame(1, AvaliacaoNota::count());
    }

    public function test_anular_poe_valor_null_e_exige_permissao(): void
    {
        $this->actingAs($this->professorAtribuido($this->c));
        $nota = $this->lancar('7');

        try {
            app(AnularNotaAction::class)->executar($nota->id, false, 'Prova repetida');
            $this->fail('O professor não pode anular.');
        } catch (AuthorizationException) {
        }

        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com'));
        $anulada = app(AnularNotaAction::class)->executar($nota->id, false, 'Prova repetida');

        $this->assertNull($anulada->valor);
        $this->assertSame(EstadoNota::ANULADA, $anulada->estado);
        $this->assertSame('7.00', AvaliacaoNotaHistorico::orderByDesc('id')->first()->valor_anterior);
    }

    public function test_anulada_conta_zero_distingue_se_de_anulada(): void
    {
        $this->actingAs($this->professorAtribuido($this->c));
        $nota = $this->lancar('7');
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com'));

        $anulada = app(AnularNotaAction::class)->executar($nota->id, true, 'Decisão da direcção');

        $this->assertSame(EstadoNota::ANULADA_CONTA_ZERO, $anulada->estado);
        $this->assertNull($anulada->valor);
    }

    public function test_inscricao_de_outra_turma_e_rejeitada(): void
    {
        $this->actingAs($this->professorAtribuido($this->c));
        $turmaB = \Modules\Turma\Models\Turma::create([
            'ano_lectivo_id' => $this->c['ano']->id, 'nivel_academico_id' => $this->c['nivel']->id,
            'curso_id' => $this->c['turma']->curso_id, 'codigo' => 'TUB', 'nome' => 'TurmaB',
        ]);
        $deOutraTurma = $this->inscreverAluno([...$this->c, 'turma' => $turmaB]);

        $this->expectException(ValidationException::class);
        $this->lancar('7', null, $deOutraTurma);
    }

    public function test_sem_regra_configurada_nao_se_lanca(): void
    {
        $this->actingAs($this->professorAtribuido($this->c));
        \Modules\Avaliacao\Models\AvaliacaoRegra::query()->delete();

        $this->expectException(ValidationException::class);
        $this->lancar('7');
    }
}
