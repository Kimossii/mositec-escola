<?php

namespace Modules\Avaliacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Avaliacao\Actions\CriarInstrumentoAction;
use Modules\Avaliacao\Actions\GuardarRegraAvaliacaoAction;
use Modules\Avaliacao\DTO\InstrumentoDTO;
use Modules\Avaliacao\DTO\RegraAvaliacaoDTO;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Enums\TratamentoDeNegativas;
use Modules\Avaliacao\Models\AvaliacaoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoNota;
use Modules\Avaliacao\Models\AvaliacaoRegra;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Permissao\Enums\Perfil;
use Modules\Turma\Models\Turma;
use Tests\TestCase;

class MiniPautaHttpTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    private array $c;

    private AvaliacaoRegra $regra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->c = $this->cenarioDeAvaliacao();
        $this->regra = app(GuardarRegraAvaliacaoAction::class)->executar(new RegraAvaliacaoDTO(
            ano_lectivo_id: $this->c['ano']->id, nivel_ids: [$this->c['nivel']->id], escala_maxima: 10, nota_minima: '5',
        ));
        $this->definirPrazo($this->c['periodos'][1]->id, TipoInstrumento::MAC, '2026-04-10');
        $this->travelTo('2026-03-15 10:00:00');
    }

    private function url(array $extra = []): string
    {
        return '/avaliacao/notas?'.http_build_query(['turma' => $this->c['turma']->id, 'disciplina' => $this->c['disciplina']->id, ...$extra]);
    }

    private function instrumento(): AvaliacaoInstrumento
    {
        return app(CriarInstrumentoAction::class)->executar(new InstrumentoDTO(
            $this->c['turma']->id, $this->c['disciplina']->id, $this->c['periodos'][1]->id, TipoInstrumento::MAC, 'Avaliação 1',
        ));
    }

    public function test_mini_pauta_mostra_so_os_alunos_da_turma_e_disciplina(): void
    {
        $professor = $this->professorAtribuido($this->c);
        $this->inscreverAluno($this->c, 'Ana');
        $turmaB = Turma::create([
            'ano_lectivo_id' => $this->c['ano']->id, 'nivel_academico_id' => $this->c['nivel']->id,
            'curso_id' => $this->c['turma']->curso_id, 'codigo' => 'TUB', 'nome' => 'TurmaB',
        ]);
        $this->inscreverAluno([...$this->c, 'turma' => $turmaB], 'Intruso');

        $this->actingAs($professor)->get($this->url())->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Avaliacao/Notas/MiniPauta')
            ->where('pauta.regra.escala_maxima', 10)
            ->has('pauta.alunos', 1)
            ->where('pauta.alunos.0.nome', fn ($nome) => str_starts_with($nome, 'Ana'))
            ->where('pauta.periodo_id', $this->c['periodos'][1]->id)
            ->where('pauta.prazos.MAC.encerrado', false));
    }

    public function test_a_mini_pauta_marca_a_negativa_do_trimestre_aberto_logo_ao_lancar_as_notas(): void
    {
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com');
        $inscricao = $this->inscreverAluno($this->c, 'Ana');
        $this->notasCompletas($this->c, $inscricao, [1 => ['2', '2']]);

        // Regra por defeito (reprova): a negativa aparece com a consequência.
        $this->actingAs($admin)->get($this->url())->assertInertia(fn (Assert $p) => $p
            ->where('pauta.alunos.0.negativa', true)
            ->where('pauta.regra.trata_negativas', false)
            ->where('pauta.regra.consequencia_negativa', 'Em risco de reprovar'));

        // Onde o aluno tem de transitar, a negativa pede tratamento; depois de tratada mostra a medida.
        $this->regra->update(['tratamento_negativas' => TratamentoDeNegativas::TRANSITA_TRATANDO_NEGATIVAS]);
        $this->actingAs($admin)->get($this->url())->assertInertia(fn (Assert $p) => $p
            ->where('pauta.regra.trata_negativas', true)
            ->where('pauta.alunos.0.medida_negativa', null)
            ->where('pode.tratar_negativa', true));

        $this->actingAs($admin)->post('/avaliacao/notas/negativas', ['inscricao_disciplina_id' => $inscricao->id, 'medida' => 'Aulas de apoio.'])->assertRedirect();
        $this->actingAs($admin)->get($this->url())->assertInertia(fn (Assert $p) => $p->where('pauta.alunos.0.medida_negativa', 'Aulas de apoio.'));
    }

    public function test_mt_negativa_no_3_trimestre_e_informativa_quando_a_media_final_ainda_passa(): void
    {
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com');
        $inscricao = $this->inscreverAluno($this->c, 'Ana');
        $this->notasCompletas($this->c, $inscricao, [1 => ['9', '9'], 2 => ['9', '9'], 3 => ['2', '2']]); // MT3 = 2, MFD ≈ 6,67 (mínimo 5)

        $this->actingAs($admin)->get($this->url(['periodo' => $this->c['periodos'][3]->id]))->assertInertia(fn (Assert $p) => $p
            ->where('pauta.alunos.0.mt_negativa', true)
            ->where('pauta.alunos.0.negativa', false)); // não decide a passagem: sem selo de negativa nem botão de tratar
    }

    public function test_so_trata_negativas_quem_tem_a_permissao_e_so_onde_o_aluno_tem_de_transitar(): void
    {
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com');
        $inscricao = $this->inscreverAluno($this->c, 'Ana');
        $this->notasCompletas($this->c, $inscricao, [1 => ['2', '2']]);
        $dados = ['inscricao_disciplina_id' => $inscricao->id, 'medida' => 'Apoio.'];

        // Classe que reprova: não há tratamento de negativas.
        $this->actingAs($admin)->post('/avaliacao/notas/negativas', $dados)->assertSessionHasErrors('inscricao_disciplina_id');

        // Sem a permissão: 403.
        $this->regra->update(['tratamento_negativas' => TratamentoDeNegativas::TRANSITA_TRATANDO_NEGATIVAS]);
        $semPermissao = $this->criarUtilizadorComPerfil(Perfil::FUNCIONARIO, 'func@example.com');
        $this->actingAs($semPermissao)->post('/avaliacao/notas/negativas', $dados)->assertForbidden();
    }

    public function test_docente_nao_atribuido_recebe_403(): void
    {
        $outro = $this->criarUtilizadorComPerfil(Perfil::PROFESSOR, 'outro@example.com');

        $this->actingAs($outro)->get($this->url())->assertForbidden();
    }

    public function test_admin_ve_a_mini_pauta_e_as_opcoes_do_ano_activo(): void
    {
        $this->inscreverAluno($this->c);
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com');

        $this->actingAs($admin)->get($this->url())->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('opcoes', 1)
            ->where('opcoes.0.turma_id', $this->c['turma']->id)
            ->has('opcoes.0.disciplinas', 1));
    }

    public function test_sem_regra_guardada_a_pagina_abre_com_a_regra_por_defeito(): void
    {
        AvaliacaoRegra::query()->delete();
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com');

        $this->actingAs($admin)->get($this->url())->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('pauta.regra.escala_maxima', 10)
            ->where('pauta.regra.nota_minima', '5.00'));
    }

    public function test_classe_sem_valores_por_defeito_e_sem_regra_abre_com_aviso(): void
    {
        AvaliacaoRegra::query()->delete();
        $this->c['nivel']->update(['codigo' => 'OUTRO']);
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com');

        $this->actingAs($admin)->get($this->url())->assertOk()->assertInertia(fn (Assert $p) => $p->where('pauta.regra', null)->has('pauta.alunos', 0));
    }

    public function test_lancar_nota_via_http_respeita_o_prazo(): void
    {
        $professor = $this->professorAtribuido($this->c);
        $inscricao = $this->inscreverAluno($this->c);
        $instrumento = $this->instrumento();
        $dados = ['instrumento_id' => $instrumento->id, 'inscricao_disciplina_id' => $inscricao->id, 'valor' => '7.5'];

        $this->actingAs($professor)->post('/avaliacao/notas', $dados)->assertRedirect();
        $this->assertSame('7.50', AvaliacaoNota::first()->valor);

        $this->travelTo('2026-04-20 10:00:00');
        $this->actingAs($professor)->post('/avaliacao/notas', [...$dados, 'valor' => '9'])->assertForbidden();
        $this->assertSame('7.50', AvaliacaoNota::first()->valor);
    }

    public function test_valor_invalido_volta_com_erro_de_validacao(): void
    {
        $professor = $this->professorAtribuido($this->c);
        $inscricao = $this->inscreverAluno($this->c);
        $instrumento = $this->instrumento();

        $this->actingAs($professor)->post('/avaliacao/notas', ['instrumento_id' => $instrumento->id, 'inscricao_disciplina_id' => $inscricao->id, 'valor' => '11'])
            ->assertSessionHasErrors('valor');
        $this->assertSame(0, AvaliacaoNota::count());
    }

    public function test_professor_cria_instrumento_da_sua_turma_mas_nao_de_outra(): void
    {
        $professor = $this->professorAtribuido($this->c);
        $dados = [
            'turma_id' => $this->c['turma']->id, 'disciplina_id' => $this->c['disciplina']->id, 'periodo_id' => $this->c['periodos'][1]->id,
            'tipo' => TipoInstrumento::MAC->value, 'nome' => 'Teste 1', 'obrigatorio' => true,
        ];

        $this->actingAs($professor)->post('/avaliacao/notas/instrumentos', $dados)->assertRedirect();
        $this->assertSame(1, AvaliacaoInstrumento::count());

        $outra = $this->criarUtilizadorComPerfil(Perfil::PROFESSOR, 'outro@example.com');
        $this->actingAs($outra)->post('/avaliacao/notas/instrumentos', [...$dados, 'nome' => 'Intruso'])->assertForbidden();
        $this->assertSame(1, AvaliacaoInstrumento::count());
    }

    public function test_sem_sessao_redireciona_para_login(): void
    {
        $this->get($this->url())->assertRedirect();
    }
}
