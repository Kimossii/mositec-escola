<?php

namespace Modules\Avaliacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Avaliacao\Models\AvaliacaoEscala;
use Modules\Avaliacao\Models\AvaliacaoQualitativa;
use Modules\Avaliacao\Models\AvaliacaoRegraQualitativa;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Permissao\Enums\Perfil;
use Tests\TestCase;

class QualitativaHttpTest extends TestCase
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
        $this->inscricao = $this->inscreverAluno($this->c, 'Ana');
        $this->travelTo('2026-03-15 10:00:00');
    }

    private function url(): string
    {
        return '/avaliacao/notas?'.http_build_query(['turma' => $this->c['turma']->id, 'disciplina' => $this->c['disciplina']->id]);
    }

    private function payload(string $codigo = 'B', array $o = []): array
    {
        return ['inscricao_disciplina_id' => $this->inscricao->id, 'periodo_id' => $this->c['periodos'][1]->id,
            'escala_nivel_id' => $this->escala->niveis->firstWhere('codigo', $codigo)->id, ...$o];
    }

    public function test_mini_pauta_de_classe_qualitativa_traz_a_escala_e_as_criancas(): void
    {
        $this->actingAs($this->professorAtribuido($this->c))->get($this->url())->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('pauta.tipo', 'qualitativa')
            ->where('pauta.escala.nome', 'Escala A')
            ->has('pauta.escala.niveis', 4)
            ->has('pauta.alunos', 1)
            ->where('pauta.alunos.0.nivel_id', null)
            ->where('pauta.prazo.encerrado', false));
    }

    public function test_professor_atribuido_lanca_e_outro_professor_leva_403(): void
    {
        $professor = $this->professorAtribuido($this->c);

        $this->actingAs($professor)->post('/avaliacao/notas/qualitativas', $this->payload('MB', ['observacao' => 'Muito participativa']))->assertRedirect();
        $this->assertSame('Muito participativa', AvaliacaoQualitativa::firstOrFail()->observacao);

        $outro = $this->criarUtilizadorComPerfil(Perfil::PROFESSOR, 'outro@example.com');
        $this->actingAs($outro)->post('/avaliacao/notas/qualitativas', $this->payload('SUF'))->assertForbidden();
        $this->assertSame(1, AvaliacaoQualitativa::count());
    }

    public function test_nivel_de_outra_escala_volta_com_erro(): void
    {
        $outra = $this->guardarEscala(null, 'Outra', [['codigo' => 'X', 'designacao' => 'Xis'], ['codigo' => 'Y', 'designacao' => 'Ipsilon']]);

        $this->actingAs($this->professorAtribuido($this->c))->post('/avaliacao/notas/qualitativas', $this->payload('B', ['escala_nivel_id' => $outra->niveis[0]->id]))
            ->assertSessionHasErrors('escala_nivel_id');
        $this->assertSame(0, AvaliacaoQualitativa::count());
    }

    public function test_configuracao_mostra_escalas_e_o_estado_das_classes_qualitativas(): void
    {
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com');

        $this->actingAs($admin)->get('/avaliacao/configuracao')->assertInertia(fn (Assert $p) => $p
            ->has('escalas', 1)
            ->where('escalas.0.niveis.0.codigo', 'INS')
            ->where('regras.0.qualitativa', true)
            ->where('regras.0.escala_id', $this->escala->id)
            ->where('regras.0.nivel_ids', [$this->c['nivel']->id]));
    }

    public function test_admin_cria_escala_e_escolhe_a_da_classe_via_http(): void
    {
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com');
        $niveis = [['codigo' => 'NA', 'designacao' => 'Não observado'], ['codigo' => 'ED', 'designacao' => 'Em desenvolvimento'], ['codigo' => 'AD', 'designacao' => 'Atingiu o esperado']];

        $this->actingAs($admin)->post('/avaliacao/configuracao/escalas', ['nome' => 'Escala B', 'niveis' => $niveis])->assertRedirect();
        $nova = AvaliacaoEscala::where('nome', 'Escala B')->firstOrFail();

        $this->actingAs($admin)->post('/avaliacao/configuracao/regras-qualitativas', [
            'ano_lectivo_id' => $this->c['ano']->id, 'nome' => 'Creche e Pré-Escolar', 'nivel_ids' => [$this->c['nivel']->id], 'escala_id' => $nova->id,
        ])->assertRedirect();
        $this->assertSame($nova->id, AvaliacaoRegraQualitativa::firstOrFail()->escala_id);
    }

    public function test_escala_invalida_volta_com_erro_e_professor_nao_configura(): void
    {
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com');
        $repetidos = [['codigo' => 'A', 'designacao' => 'Um'], ['codigo' => 'a', 'designacao' => 'Dois']];

        $this->actingAs($admin)->post('/avaliacao/configuracao/escalas', ['nome' => 'Má', 'niveis' => $repetidos])->assertSessionHasErrors('niveis');

        $professor = $this->criarUtilizadorComPerfil(Perfil::PROFESSOR, 'prof@example.com');
        $this->actingAs($professor)->post('/avaliacao/configuracao/escalas', ['nome' => 'Intrusa', 'niveis' => $repetidos])->assertForbidden();
        $this->assertSame(1, AvaliacaoEscala::count());
    }

    public function test_a_pauta_da_classe_qualitativa_abre_na_mesma_pagina(): void
    {
        $this->actingAs($this->professorAtribuido($this->c))->post('/avaliacao/notas/qualitativas', $this->payload('B'));
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com');

        $this->actingAs($admin)->get('/avaliacao/pautas?turma='.$this->c['turma']->id)->assertInertia(fn (Assert $p) => $p
            ->where('pauta.tipo', 'qualitativa')
            ->where('pauta.regra.escala_nome', 'Escala A')
            ->where("pauta.alunos.0.celulas.{$this->c['disciplina']->id}.mt1", 'Bom')
            ->where('pauta.pode_aprovar', false));
    }
}
