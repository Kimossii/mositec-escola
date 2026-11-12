<?php

namespace Modules\Avaliacao\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Avaliacao\Actions\AprovarPautaAction;
use Modules\Avaliacao\Actions\LancarCompetenciaAction;
use Modules\Avaliacao\Actions\LancarQualitativaAction;
use Modules\Avaliacao\DTO\LancarCompetenciaDTO;
use Modules\Avaliacao\DTO\LancarQualitativaDTO;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoCompetencia;
use Modules\Avaliacao\Models\AvaliacaoCompetenciaRegisto;
use Modules\Avaliacao\Models\AvaliacaoEscala;
use Modules\Avaliacao\Models\AvaliacaoRegraQualitativa;
use Modules\Avaliacao\Services\MiniPautaDocumentoService;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Permissao\Enums\Perfil;
use Tests\TestCase;

class CompetenciasTest extends TestCase
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
        $this->definir([['descricao' => 'Reconhece as cores primárias'], ['descricao' => 'Conta até 5'], ['descricao' => 'Recorta com precisão']]);
    }

    private function definir(array $itens, ?int $nivelId = null, ?int $disciplinaId = null): void
    {
        $this->guardarCompetencias($disciplinaId ?? $this->c['disciplina']->id, $nivelId, $itens);
    }

    /** @return Collection<int, AvaliacaoCompetencia> */
    private function competencias(): Collection
    {
        return AvaliacaoCompetencia::orderBy('ordem')->get();
    }

    private function nivel(string $codigo): int
    {
        return $this->escala->niveis->firstWhere('codigo', $codigo)->id;
    }

    private function lancar(int $competenciaIndex, string $codigo, ?InscricaoDisciplina $inscricao = null, int $trimestre = 1, ?string $motivo = null): AvaliacaoCompetenciaRegisto
    {
        return app(LancarCompetenciaAction::class)->executar(new LancarCompetenciaDTO(
            ($inscricao ?? $this->inscricao)->id, $this->competencias()[$competenciaIndex]->id, $this->c['periodos'][$trimestre]->id, $this->nivel($codigo), $motivo,
        ));
    }

    private function admin()
    {
        return $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com');
    }

    private function pautaQualitativa(): array
    {
        $url = '/avaliacao/notas?'.http_build_query(['turma' => $this->c['turma']->id, 'disciplina' => $this->c['disciplina']->id, 'periodo' => $this->c['periodos'][1]->id]);

        return $this->get($url)->viewData('page')['props']['pauta'];
    }

    // ---- catálogo

    public function test_guarda_as_competencias_pela_ordem_e_permite_reordenar_e_desactivar(): void
    {
        $this->assertSame(['Reconhece as cores primárias', 'Conta até 5', 'Recorta com precisão'], $this->competencias()->pluck('descricao')->all());
        [$a, $b, $c] = $this->competencias()->all();

        $this->definir([['id' => $c->id, 'descricao' => 'Recorta com precisão'], ['id' => $a->id, 'descricao' => 'Cores primárias', 'ativa' => false], ['id' => $b->id, 'descricao' => 'Conta até 5']]);

        $this->assertSame([$c->id, $a->id, $b->id], $this->competencias()->pluck('id')->all());
        $this->assertFalse(AvaliacaoCompetencia::find($a->id)->ativa);
        $this->assertSame('Cores primárias', AvaliacaoCompetencia::find($a->id)->descricao);
    }

    public function test_valida_descricao_repeticao_maximo_e_classe(): void
    {
        $invalidos = [
            [[['descricao' => '  ']], null],
            [[['descricao' => 'Igual'], ['descricao' => 'igual']], null],
            [array_map(fn ($i) => ['descricao' => "C{$i}"], range(1, 31)), null],
            [[['descricao' => str_repeat('x', 201)]], null],
            [[['descricao' => 'Boa']], $this->cenarioDeAvaliacao('PRI_3', 'Z')['nivel']->id], // classe do primário
        ];

        foreach ($invalidos as [$itens, $nivel]) {
            try {
                $this->definir($itens, $nivel);
                $this->fail('Esperava ValidationException.');
            } catch (ValidationException) {
            }
        }

        $this->assertSame(3, AvaliacaoCompetencia::count());
    }

    public function test_competencia_ja_avaliada_nao_se_remove_mas_a_que_nao_foi_usada_sim(): void
    {
        $this->actingAs($this->admin());
        $this->lancar(0, 'B');
        [$usada, $livre1, $livre2] = $this->competencias()->all();

        $this->definir([['id' => $usada->id, 'descricao' => $usada->descricao]]); // remove as duas livres
        $this->assertSame(1, AvaliacaoCompetencia::count());

        $this->expectException(ValidationException::class);
        $this->definir([]); // tentaria remover a usada
    }

    public function test_competencias_de_uma_classe_nao_misturam_com_as_de_todas(): void
    {
        $this->definir([['descricao' => 'Só desta sala']], $this->c['nivel']->id);

        $this->assertSame(4, AvaliacaoCompetencia::count());
        $this->assertSame(3, AvaliacaoCompetencia::whereNull('nivel_academico_id')->count());
        $this->definir([['descricao' => 'Conta até 5']]); // só mexe nas «de todas»
        $this->assertSame(2, AvaliacaoCompetencia::count());
    }

    // ---- registo

    public function test_docente_atribuido_regista_e_refaz_sem_duplicar(): void
    {
        $this->actingAs($this->professorAtribuido($this->c));

        $this->lancar(0, 'B');
        $this->lancar(0, 'MB');

        $this->assertSame(1, AvaliacaoCompetenciaRegisto::count());
        $this->assertSame($this->nivel('MB'), AvaliacaoCompetenciaRegisto::firstOrFail()->escala_nivel_id);
    }

    public function test_quem_nao_lecciona_a_area_nao_regista(): void
    {
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::PROFESSOR, 'outro@example.com'));

        $this->expectException(AuthorizationException::class);
        $this->lancar(0, 'B');
    }

    public function test_so_aceita_competencias_activas_da_area_e_da_classe_e_niveis_da_escala(): void
    {
        $this->actingAs($this->admin());
        $outraDisciplina = $this->outraDisciplina($this->c, 'MOT')['disciplina'];
        $this->definir([['descricao' => 'Salta com os dois pés']], null, $outraDisciplina->id);
        $deOutraArea = AvaliacaoCompetencia::where('disciplina_id', $outraDisciplina->id)->firstOrFail();
        $outraClasse = $this->cenarioDeAvaliacao('PRE_II', 'Y')['nivel'];
        $outraClasse->update(['etapa_ensino' => \Modules\Estabelecimento\Enums\EtapaEnsinoEnum::PRE_ESCOLAR]);
        $this->definir([['descricao' => 'Só da outra sala']], $outraClasse->id);
        $daOutraSala = AvaliacaoCompetencia::where('nivel_academico_id', $outraClasse->id)->firstOrFail();
        $this->competencias()->first()->update(['ativa' => false]);
        $outraEscala = $this->guardarEscala(null, 'Outra', [['codigo' => 'X', 'designacao' => 'Xis'], ['codigo' => 'Y', 'designacao' => 'Ipsilon']]);

        $tentar = fn (int $competenciaId, ?int $nivelId = null) => app(LancarCompetenciaAction::class)->executar(new LancarCompetenciaDTO(
            $this->inscricao->id, $competenciaId, $this->c['periodos'][1]->id, $nivelId ?? $this->nivel('B'),
        ));

        foreach ([[$deOutraArea->id, null], [$daOutraSala->id, null], [$this->competencias()->first()->id, null], [AvaliacaoCompetencia::where('descricao', 'Conta até 5')->value('id'), $outraEscala->niveis[0]->id]] as [$id, $nivel]) {
            try {
                $tentar($id, $nivel);
                $this->fail('Esperava ValidationException.');
            } catch (ValidationException) {
            }
        }

        $this->assertSame(0, AvaliacaoCompetenciaRegisto::count());
    }

    public function test_o_prazo_qualitativo_tambem_manda_nas_competencias(): void
    {
        $this->definirPrazo($this->c['periodos'][1]->id, TipoInstrumento::QUALITATIVA, '2026-03-01');
        $this->actingAs($this->professorAtribuido($this->c));

        try {
            $this->lancar(0, 'B');
            $this->fail('O professor não regista depois do prazo.');
        } catch (AuthorizationException) {
        }

        $this->actingAs($this->admin());
        $this->lancar(0, 'B', null, 1, 'Entregue em atraso');
        $this->assertSame(1, AvaliacaoCompetenciaRegisto::count());
    }

    public function test_com_a_pauta_definitiva_as_competencias_ficam_bloqueadas(): void
    {
        $this->actingAs($this->admin());
        foreach ([1 => 'B', 2 => 'B', 3 => 'MB'] as $n => $codigo) {
            app(LancarQualitativaAction::class)->executar(new LancarQualitativaDTO($this->inscricao->id, $this->c['periodos'][$n]->id, $this->nivel($codigo)));
        }
        app(AprovarPautaAction::class)->executar($this->c['turma']->id);

        $this->expectException(ValidationException::class);
        $this->lancar(0, 'B');
    }

    // ---- sugestão e ecrã

    public function test_a_mini_pauta_traz_as_competencias_e_a_sugestao_do_nivel_mais_frequente(): void
    {
        $this->actingAs($this->professorAtribuido($this->c));
        $this->lancar(0, 'MB');
        $this->lancar(1, 'B');
        $this->lancar(2, 'MB');

        $p = $this->pautaQualitativa();

        $this->assertCount(3, $p['competencias']);
        $this->assertSame(3, count($p['alunos'][0]['competencias']));
        $this->assertSame(['nivel_id' => $this->nivel('MB'), 'designacao' => 'Muito Bom', 'avaliadas' => 3, 'total' => 3], $p['alunos'][0]['sugestao']);
    }

    public function test_em_empate_a_sugestao_fica_com_o_nivel_mais_baixo_e_sem_registos_nao_ha_sugestao(): void
    {
        $this->actingAs($this->professorAtribuido($this->c));
        $this->assertNull($this->pautaQualitativa()['alunos'][0]['sugestao']);

        $this->lancar(0, 'MB');
        $this->lancar(1, 'SUF');

        $this->assertSame('Suficiente', $this->pautaQualitativa()['alunos'][0]['sugestao']['designacao']);
    }

    public function test_competencia_inactiva_ou_de_outra_classe_nao_aparece_na_mini_pauta(): void
    {
        $this->actingAs($this->professorAtribuido($this->c));
        $this->competencias()->first()->update(['ativa' => false]);
        $outraClasse = $this->cenarioDeAvaliacao('PRE_III', 'X')['nivel'];
        $outraClasse->update(['etapa_ensino' => \Modules\Estabelecimento\Enums\EtapaEnsinoEnum::PRE_ESCOLAR]);
        $this->definir([['descricao' => 'Da outra sala']], $outraClasse->id);

        $this->assertSame(['Conta até 5', 'Recorta com precisão'], array_column($this->pautaQualitativa()['competencias'], 'descricao'));
    }

    public function test_o_ecra_lanca_a_competencia_via_http(): void
    {
        $professor = $this->professorAtribuido($this->c);
        $dados = ['inscricao_disciplina_id' => $this->inscricao->id, 'competencia_id' => $this->competencias()[0]->id, 'periodo_id' => $this->c['periodos'][1]->id, 'escala_nivel_id' => $this->nivel('B')];

        $this->actingAs($professor)->post('/avaliacao/notas/competencias', $dados)->assertRedirect();
        $this->assertSame(1, AvaliacaoCompetenciaRegisto::count());

        $outro = $this->criarUtilizadorComPerfil(Perfil::PROFESSOR, 'outro@example.com');
        $this->actingAs($outro)->post('/avaliacao/notas/competencias', [...$dados, 'escala_nivel_id' => $this->nivel('MB')])->assertForbidden();
        $this->actingAs($professor)->post('/avaliacao/notas/competencias', [...$dados, 'competencia_id' => 999999])->assertNotFound();
    }

    // ---- configuração

    public function test_configuracao_mostra_e_guarda_as_competencias_e_o_professor_nao_configura(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/avaliacao/configuracao')->assertInertia(fn (Assert $p) => $p
            ->has('competencias', 3)->where('competencias.0.descricao', 'Reconhece as cores primárias')->has('disciplinas'));

        $this->actingAs($admin)->post('/avaliacao/configuracao/competencias', [
            'disciplina_id' => $this->c['disciplina']->id, 'nivel_academico_id' => null, 'competencias' => [['descricao' => 'Só uma'], ['descricao' => 'Outra', 'ativa' => false]],
        ])->assertRedirect();
        $this->assertSame(['Só uma', 'Outra'], $this->competencias()->pluck('descricao')->all());

        $this->actingAs($admin)->post('/avaliacao/configuracao/competencias', ['disciplina_id' => $this->c['disciplina']->id, 'competencias' => [['descricao' => 'A'], ['descricao' => 'a']]])->assertSessionHasErrors('competencias');

        $professor = $this->criarUtilizadorComPerfil(Perfil::PROFESSOR, 'prof@example.com');
        $this->actingAs($professor)->post('/avaliacao/configuracao/competencias', ['disciplina_id' => $this->c['disciplina']->id, 'competencias' => []])->assertForbidden();
    }

    // ---- exportação

    public function test_mini_pauta_qualitativa_leva_os_niveis_dos_trimestres_e_as_observacoes(): void
    {
        $this->actingAs($this->admin());
        foreach ([1 => ['B', 'Boa participação'], 2 => ['SUF', null], 3 => ['MB', 'Evoluiu muito']] as $n => [$codigo, $obs]) {
            app(LancarQualitativaAction::class)->executar(new LancarQualitativaDTO($this->inscricao->id, $this->c['periodos'][$n]->id, $this->nivel($codigo), $obs));
        }

        $d = app(MiniPautaDocumentoService::class)->paraTurmaDisciplina($this->c['turma']->id, $this->c['disciplina']->id);

        $this->assertSame('MINI-PAUTA', $d->titulo);
        $this->assertSame(['N.º', 'Nome da criança'], $d->fixas);
        $this->assertSame(['Observações do educador'], $d->finais);
        $this->assertContains(['ESCALA', 'Escala A'], $d->meta);
        $this->assertSame(['1', $this->inscricao->matricula->aluno->dadosPessoa->nome_completo, 'Bom', 'Suficiente', 'Muito Bom', 'Muito Bom', 'I T: Boa participação · III T: Evoluiu muito'], $d->linhas[0]);
        $this->assertSame($d->totalDeColunas(), count($d->linhas[0]));
    }

    public function test_detalhe_por_competencia_tem_uma_coluna_por_competencia_a_legenda_e_o_nivel_da_area(): void
    {
        $this->actingAs($this->admin());
        $this->lancar(0, 'B');
        $this->lancar(1, 'MB');
        app(LancarQualitativaAction::class)->executar(new LancarQualitativaDTO($this->inscricao->id, $this->c['periodos'][1]->id, $this->nivel('B')));

        $d = app(MiniPautaDocumentoService::class)->competenciasParaTurmaDisciplina($this->c['turma']->id, $this->c['disciplina']->id);

        $this->assertSame('COMPETÊNCIAS POR ÁREA', $d->titulo);
        $this->assertSame(['C1', 'C2', 'C3'], $d->grupos[0]['colunas']);
        $this->assertSame(['Nível da área'], $d->finais);
        $this->assertSame([$this->inscricao->matricula->aluno->dadosPessoa->nome_completo, 'Bom', 'Muito Bom', '', 'Bom'], array_slice($d->linhas[0], 1));
        $this->assertStringContainsString('C1 — Reconhece as cores primárias · C2 — Conta até 5 · C3 — Recorta com precisão', (string) $d->nota);
        $this->assertContains(['PERÍODO', '1.º Trimestre'], $d->meta);
    }

    public function test_detalhe_por_competencia_exige_competencias_e_classe_qualitativa_e_exporta_via_http(): void
    {
        $admin = $this->admin();
        $url = fn (array $c, array $extra = []) => '/avaliacao/notas/exportar?'.http_build_query(['turma' => $c['turma']->id, 'disciplina' => $c['disciplina']->id, 'detalhe' => 'competencias', 'formato' => 'pdf', ...$extra]);

        $this->actingAs($admin)->get($url($this->c))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($admin)->get($url($this->c, ['formato' => 'excel', 'periodo' => $this->c['periodos'][2]->id]))->assertOk();

        AvaliacaoCompetencia::query()->delete();
        $this->actingAs($admin)->get($url($this->c))->assertSessionHasErrors('turma');

        $numerica = $this->cenarioDeAvaliacao('PRI_3', 'N');
        $this->actingAs($admin)->get($url($numerica))->assertSessionHasErrors('turma');
    }

    // ---- tenancy

    public function test_as_competencias_do_tenant_b_sao_inalcancaveis(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $b = $this->noTenant($outro, function () {
            [$c, $escala] = $this->cenarioQualitativo('B');
            $this->guardarCompetencias($c['disciplina']->id, null, [['descricao' => 'Só da escola B']]);

            return ['c' => $c, 'escala' => $escala, 'inscricao' => $this->inscreverAluno($c), 'competencia' => AvaliacaoCompetencia::firstOrFail()];
        });
        $admin = $this->admin();

        $this->assertSame(3, AvaliacaoCompetencia::count());
        $this->assertSame(0, AvaliacaoCompetencia::where('descricao', 'Só da escola B')->count());

        $this->actingAs($admin)->post('/avaliacao/notas/competencias', [
            'inscricao_disciplina_id' => $b['inscricao']->id, 'competencia_id' => $b['competencia']->id, 'periodo_id' => $b['c']['periodos'][1]->id, 'escala_nivel_id' => $b['escala']->niveis[0]->id,
        ])->assertNotFound();
        $this->actingAs($admin)->post('/avaliacao/configuracao/competencias', ['disciplina_id' => $b['c']['disciplina']->id, 'competencias' => [['descricao' => 'Intrusa']]])->assertNotFound();
        $this->assertSame(1, $this->noTenant($outro, fn () => AvaliacaoCompetencia::count()));
    }
}
