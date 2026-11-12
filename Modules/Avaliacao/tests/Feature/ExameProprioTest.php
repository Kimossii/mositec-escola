<?php

namespace Modules\Avaliacao\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\Actions\AprovarPautaAction;
use Modules\Avaliacao\Actions\GuardarRegraAvaliacaoAction;
use Modules\Avaliacao\DTO\RegraAvaliacaoDTO;
use Modules\Avaliacao\Enums\Decisao;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoDecisao;
use Modules\Avaliacao\Models\AvaliacaoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoNota;
use Modules\Avaliacao\Models\AvaliacaoPautaLinha;
use Modules\Avaliacao\Models\AvaliacaoRegra;
use Modules\Avaliacao\Services\PautaFinalService;
use Modules\Avaliacao\Services\ResultadoDisciplinaService;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Permissao\Enums\Perfil;
use Tests\TestCase;

class ExameProprioTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    private array $c;

    private InscricaoDisciplina $inscricao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->c = $this->cenarioDeAvaliacao('PRI_6');
        app(GuardarRegraAvaliacaoAction::class)->executar(new RegraAvaliacaoDTO($this->c['ano']->id, [$this->c['nivel']->id], 10, '5'));
        $this->inscricao = $this->inscreverAluno($this->c, 'Ana');
        $this->travelTo('2026-03-15 10:00:00');
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com'));
    }

    /** Dá o prazo do recurso por terminado (antes de «hoje»): uma disciplina abaixo do mínimo sem NER passa a «não realizou». */
    private function fecharORecurso(): void
    {
        $this->definirPrazo($this->c['periodos'][3]->id, TipoInstrumento::RECURSO, '2026-03-01');
    }

    private function mfd(string $nota): void
    {
        $this->notasCompletas($this->c, $this->inscricao, [1 => [$nota, $nota], 2 => [$nota, $nota], 3 => [$nota, $nota]]);
    }

    private function exame(string $valor, ?string $motivo = null): AvaliacaoNota
    {
        return $this->lancarExame($this->c['turma']->id, $this->c['disciplina']->id, $this->inscricao->id, $valor, $motivo);
    }

    private function pauta(): array
    {
        return app(PautaFinalService::class)->paraTurma($this->c['turma']->id);
    }

    private function celula(): array
    {
        return $this->pauta()['alunos'][0]['celulas'][$this->c['disciplina']->id];
    }

    public function test_sem_exame_lancado_o_resultado_fica_pendente_e_nao_se_aprova(): void
    {
        $this->mfd('8');

        $p = $this->pauta();

        $this->assertTrue($p['com_exame']);
        $this->assertSame('8.00', $this->celula()['mfd']);
        $this->assertNull($this->celula()['mf']);
        $this->assertTrue($this->celula()['exame_em_falta']);
        $this->assertNull($p['alunos'][0]['decisao']);
        $this->assertFalse($p['pode_aprovar']);
        $this->assertStringContainsString('Faltam notas de exame em 1 aluno', implode(' ', $p['bloqueios']));
    }

    public function test_mf_e_40_por_cento_da_mfd_mais_60_por_cento_do_exame(): void
    {
        $this->mfd('8');
        $this->exame('10');

        $r = app(ResultadoDisciplinaService::class)->calcular($this->inscricao->id);

        $this->assertSame('10.0000000000', $r->ne === null ? null : bcadd($r->ne, '0', 10));
        $this->assertSame('9.2000000000', $r->mf); // 8×0,4 + 10×0,6, sem dividir por 2
        $this->assertSame('9.20', $r->mfArredondada);
        $this->assertSame('9.20', $this->celula()['nota_final']);
        $this->assertSame('Apto', $this->pauta()['alunos'][0]['decisao']['rotulo']);
        $this->assertTrue($this->pauta()['pode_aprovar']);
    }

    public function test_a_escola_escolhe_se_o_exame_parte_da_nota_final_arredondada_ou_da_exacta(): void
    {
        // Sem casas decimais: MFD exacta 7.49 aparece como 7 na pauta. Com NE 4.3 a conta decide entre 6 (exacta) e 5 (arredondada).
        AvaliacaoRegra::query()->update(['casas_decimais' => 0]);
        $this->mfd('7.49');
        $this->exame('4.3');

        AvaliacaoRegra::query()->update(['exame_usa_mfd_arredondada' => true]);
        $this->assertSame('5', app(ResultadoDisciplinaService::class)->calcular($this->inscricao->id)->mfArredondada); // 7×0,4 + 4,3×0,6 = 5,38

        AvaliacaoRegra::query()->update(['exame_usa_mfd_arredondada' => false]);
        $this->assertSame('6', app(ResultadoDisciplinaService::class)->calcular($this->inscricao->id)->mfArredondada); // 7,49×0,4 + 4,3×0,6 = 5,576
    }

    public function test_o_exame_decide_nao_a_mfd_sozinha(): void
    {
        $this->mfd('8');
        $this->exame('2'); // 8×0,4 + 2×0,6 = 4,4 < 5

        $this->assertSame('4.40', $this->celula()['mf']);
        $this->assertSame('Recurso', $this->pauta()['alunos'][0]['decisao']['rotulo']); // a MF abaixo do mínimo manda-o ao recurso, não à aprovação
        $this->assertFalse($this->celula()['aprovado']);
    }

    public function test_um_bom_exame_pode_salvar_uma_mfd_negativa(): void
    {
        $this->mfd('4');
        $this->exame('8'); // 4×0,4 + 8×0,6 = 6,4

        $this->assertSame('6.40', $this->celula()['mf']);
        $this->assertSame('Apto', $this->pauta()['alunos'][0]['decisao']['rotulo']);
    }

    public function test_exame_anulado_deixa_o_resultado_pendente_e_a_contar_como_zero_entra(): void
    {
        $this->mfd('8');
        $nota = $this->exame('10');

        $this->anularNota($nota->id, false, 'Prova repetida');
        $this->assertNull($this->celula()['mf']);
        $this->assertNull($this->pauta()['alunos'][0]['decisao']);

        $nota->refresh()->update(['estado' => \Modules\Avaliacao\Enums\EstadoNota::ANULADA_CONTA_ZERO]);
        $this->assertSame('3.20', $this->celula()['mf']); // 8×0,4 + 0×0,6
    }

    public function test_o_exame_cria_se_na_primeira_nota_no_3_trimestre_e_so_ha_um(): void
    {
        $this->mfd('8');
        $this->exame('10');
        $this->exame('7');

        $exame = AvaliacaoInstrumento::where('tipo', TipoInstrumento::EXAME->value)->get();
        $this->assertCount(1, $exame);
        $this->assertSame($this->c['periodos'][3]->id, $exame[0]->periodo_id);
        $this->assertSame('7.00', AvaliacaoNota::firstWhere('instrumento_id', $exame[0]->id)->valor);
    }

    public function test_classe_sem_exame_recusa_lancar_exame(): void
    {
        $c = $this->cenarioDeAvaliacao('PRI_5', 'Z');
        app(GuardarRegraAvaliacaoAction::class)->executar(new RegraAvaliacaoDTO($c['ano']->id, [$c['nivel']->id], 10, '5'));
        $inscricao = $this->inscreverAluno($c);

        $this->expectException(ValidationException::class);
        $this->lancarExame($c['turma']->id, $c['disciplina']->id, $inscricao->id, '8');
    }

    public function test_sem_permissao_nao_lanca_e_nao_deixa_exame_criado(): void
    {
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::PROFESSOR, 'outro@example.com'));

        try {
            $this->exame('8');
            $this->fail('Esperava AuthorizationException.');
        } catch (AuthorizationException) {
        }

        $this->assertSame(0, AvaliacaoInstrumento::where('tipo', TipoInstrumento::EXAME->value)->count());
    }

    public function test_valor_acima_da_escala_e_rejeitado_sem_deixar_exame(): void
    {
        try {
            $this->exame('11');
            $this->fail('Esperava ValidationException.');
        } catch (ValidationException) {
        }

        $this->assertSame(0, AvaliacaoInstrumento::count());
    }

    public function test_professor_atribuido_lanca_o_exame_da_sua_disciplina(): void
    {
        $this->actingAs($this->professorAtribuido($this->c));

        $this->assertSame('9.00', $this->exame('9')->valor);
    }

    public function test_aprovar_grava_ne_mf_e_decisao_apto_e_a_mfd_nao_muda_depois(): void
    {
        $this->mfd('8');
        $this->exame('10');

        app(AprovarPautaAction::class)->executar($this->c['turma']->id);

        $l = AvaliacaoPautaLinha::firstOrFail();
        $this->assertSame(['10.0000', '8.0000', '9.2000'], [$l->ne, $l->mfd, $l->mf]);
        $this->assertSame(Decisao::APTO, AvaliacaoDecisao::firstOrFail()->decisao);

        AvaliacaoNota::query()->update(['valor' => '1']);
        $p = $this->pauta();
        $this->assertSame('definitiva', $p['estado']);
        $this->assertTrue($p['com_exame']);
        $this->assertSame(['10.00', '8.00', '9.20'], [$this->celula()['ne'], $this->celula()['mfd'], $this->celula()['mf']]);
    }

    public function test_mini_pauta_do_3_trimestre_mostra_o_exame_e_o_1_nao(): void
    {
        $this->inscreverAluno($this->c, 'Rui');
        $url = fn (int $trimestre) => '/avaliacao/notas?'.http_build_query(['turma' => $this->c['turma']->id, 'disciplina' => $this->c['disciplina']->id, 'periodo' => $this->c['periodos'][$trimestre]->id]);

        $this->get($url(3))->assertInertia(fn ($p) => $p->where('pauta.exame.peso_mfd', '40')->where('pauta.exame.peso_exame', '60')->has('pauta.prazos.EXAME'));
        $this->get($url(1))->assertInertia(fn ($p) => $p->where('pauta.exame', null));
    }

    public function test_lancar_exame_via_http_e_a_nota_aparece_na_mini_pauta(): void
    {
        $this->mfd('8');
        $dados = ['turma_id' => $this->c['turma']->id, 'disciplina_id' => $this->c['disciplina']->id, 'inscricao_disciplina_id' => $this->inscricao->id, 'valor' => '10'];

        $this->post('/avaliacao/notas/exame', $dados)->assertRedirect();

        $this->get('/avaliacao/notas?'.http_build_query(['turma' => $this->c['turma']->id, 'disciplina' => $this->c['disciplina']->id, 'periodo' => $this->c['periodos'][3]->id]))
            ->assertInertia(fn ($p) => $p->where('pauta.alunos.0.ne.valor', '10.00')->where('pauta.alunos.0.mf', '9.20'));
    }

    public function test_exame_invalido_ou_sem_permissao_volta_com_erro_ou_403(): void
    {
        $dados = ['turma_id' => $this->c['turma']->id, 'disciplina_id' => $this->c['disciplina']->id, 'inscricao_disciplina_id' => $this->inscricao->id, 'valor' => '11'];

        $this->post('/avaliacao/notas/exame', $dados)->assertSessionHasErrors('valor');

        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::PROFESSOR, 'outro@example.com'))->post('/avaliacao/notas/exame', [...$dados, 'valor' => '9'])->assertForbidden();
        $this->assertSame(0, AvaliacaoInstrumento::count());
    }

    public function test_configuracao_guarda_os_pesos_do_exame_e_recusa_pesos_que_nao_somam_100(): void
    {
        $base = ['ano_lectivo_id' => $this->c['ano']->id, 'nome' => 'Regra', 'nivel_ids' => [$this->c['nivel']->id], 'com_exame' => true, 'com_recurso' => true, 'sigla_prova' => 'PT', 'escala_maxima' => 10, 'nota_minima' => '5', 'peso_mac' => '50', 'peso_pt' => '50',
            'casas_decimais' => 2, 'modo_arredondamento' => 0, 'tratamento_negativas' => 0];

        $this->post('/avaliacao/configuracao/regras', [...$base, 'peso_mfd_exame' => '30', 'peso_exame' => '70'])->assertRedirect();
        $this->assertSame('70.00', \Modules\Avaliacao\Models\AvaliacaoRegra::firstOrFail()->peso_exame);

        $this->post('/avaliacao/configuracao/regras', [...$base, 'peso_mfd_exame' => '30', 'peso_exame' => '60'])->assertSessionHasErrors('peso_exame');

        $this->get('/avaliacao/configuracao')->assertInertia(fn ($p) => $p->where('regras.0.com_exame', true)->where('regras.0.peso_exame', '70.00'));
    }

    public function test_na_mini_pauta_a_negativa_so_conta_a_mf_no_3_trimestre_e_antes_disso_a_mfd(): void
    {
        $this->mfd('8');
        $this->exame('2'); // MFD 8 passa; MF 4,4 não
        $url = fn (int $trimestre) => '/avaliacao/notas?'.http_build_query(['turma' => $this->c['turma']->id, 'disciplina' => $this->c['disciplina']->id, 'periodo' => $this->c['periodos'][$trimestre]->id]);

        // No 1.º trimestre não se vê a MF: a MFD positiva não dá negativa.
        $this->get($url(1))->assertInertia(fn ($p) => $p->where('pauta.alunos.0.negativa', false)->where('pauta.alunos.0.negativa_final', false));
        // No 3.º a MF (4,4) já aparece e é negativa.
        $this->get($url(3))->assertInertia(fn ($p) => $p->where('pauta.alunos.0.negativa', true)->where('pauta.alunos.0.negativa_final', true));
    }
}
