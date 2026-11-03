<?php

namespace Modules\Avaliacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Avaliacao\Actions\CriarInstrumentoAction;
use Modules\Avaliacao\Actions\DefinirPrazoAction;
use Modules\Avaliacao\Actions\GuardarRegraAvaliacaoAction;
use Modules\Avaliacao\DTO\InstrumentoDTO;
use Modules\Avaliacao\DTO\RegraAvaliacaoDTO;
use Modules\Avaliacao\Enums\TipoInstrumento;
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
            ano_lectivo_id: $this->c['ano']->id, nivel_academico_id: $this->c['nivel']->id, escala_maxima: 10, nota_minima: '5',
        ));
        app(DefinirPrazoAction::class)->executar($this->regra->id, $this->c['periodos'][1]->id, TipoInstrumento::MAC, '2026-04-10');
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
            ->has('pauta.alunos', 1)
            ->where('pauta.alunos.0.nome', fn ($nome) => str_starts_with($nome, 'Ana'))
            ->where('pauta.periodo_id', $this->c['periodos'][1]->id)
            ->where('pauta.prazos.MAC.encerrado', false));
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

    public function test_sem_regra_a_pagina_abre_com_aviso(): void
    {
        AvaliacaoRegra::query()->delete();
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
