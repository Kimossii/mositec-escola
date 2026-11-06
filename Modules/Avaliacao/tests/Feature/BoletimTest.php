<?php

namespace Modules\Avaliacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\Actions\AprovarPautaAction;
use Modules\Avaliacao\Actions\GuardarRegraAvaliacaoAction;
use Modules\Avaliacao\Actions\LancarQualitativaAction;
use Modules\Avaliacao\DTO\LancarQualitativaDTO;
use Modules\Avaliacao\DTO\RegraAvaliacaoDTO;
use Modules\Avaliacao\Services\BoletimDocumentoService;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Enums\Perfil;
use Tests\TestCase;

class BoletimTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Estabelecimento::current()->update(['nome' => 'Complexo Escolar Teste', 'provincia' => 'Luanda', 'municipio' => 'Camama', 'responsavel_nome' => 'Glória Mendonça', 'responsavel_cargo' => 'A Directora']);
        $this->travelTo('2026-03-15 10:00:00');
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com'));
    }

    private function regra(array $c, string $minima = '5'): void
    {
        app(GuardarRegraAvaliacaoAction::class)->executar(new RegraAvaliacaoDTO($c['ano']->id, $c['nivel']->id, 10, $minima));
    }

    private function boletins(array $c, ?int $matricula = null): array
    {
        return app(BoletimDocumentoService::class)->paraTurma($c['turma']->id, $matricula);
    }

    public function test_boletim_numerico_com_a_nomenclatura_da_pauta_e_resultado_provisorio(): void
    {
        $c = $this->cenarioDeAvaliacao();
        $this->regra($c);
        $ana = $this->inscreverAluno($c, 'Ana');
        $this->notasCompletas($c, $ana, [1 => ['5.33', '7'], 2 => ['6', '7'], 3 => ['8', '9']]);

        [$b] = $this->boletins($c);

        $this->assertSame('BOLETIM DE AVALIAÇÃO', $b->titulo);
        $this->assertContains(['Nome', $ana->matricula->aluno->dadosPessoa->nome_completo], $b->identificacao);
        $this->assertContains(['Turma', 'TurmaA'], $b->identificacao);
        $this->assertSame(['Disciplina', 'MT1', 'MT2', 'MT3', 'MFD'], $b->colunas);
        $this->assertSame([$c['disciplina']->nome, '6.17', '6.50', '8.50', '7.06'], $b->linhas[0]);
        $this->assertSame('RESULTADO PROVISÓRIO', $b->resultado);
        $this->assertFalse($b->resultadoDefinitivo);
        $this->assertStringContainsString('não representa aprovação nem transição', $b->nota);
        $this->assertStringContainsString('provisórios', $b->nota);
        $this->assertSame(['O Professor', 'O Encarregado de Educação', 'A Directora'], array_column($b->assinaturas, 'rotulo'));
        $this->assertSame('Glória Mendonça', $b->assinaturas[2]['nome']);
        $this->assertSame('REPÚBLICA DE ANGOLA', $b->linhasCabecalho[0]);
    }

    public function test_so_com_a_pauta_definitiva_o_boletim_traz_a_decisao(): void
    {
        $c = $this->cenarioDeAvaliacao();
        $this->regra($c);
        $this->notasCompletas($c, $this->inscreverAluno($c), [1 => ['8', '8'], 2 => ['8', '8'], 3 => ['8', '8']]);
        app(AprovarPautaAction::class)->executar($c['turma']->id);

        [$b] = $this->boletins($c);

        $this->assertSame('TRANSITA', $b->resultado);
        $this->assertTrue($b->resultadoDefinitivo);
        $this->assertStringNotContainsString('provisórios', $b->nota);
    }

    public function test_boletim_da_6_classe_traz_exame_e_recurso_e_apto(): void
    {
        $c = $this->cenarioDeAvaliacao('PRI_6');
        $this->regra($c);
        $inscricao = $this->inscreverAluno($c);
        $this->notasCompletas($c, $inscricao, [1 => ['8', '8'], 2 => ['8', '8'], 3 => ['8', '8']]);
        $this->lancarExame($c['turma']->id, $c['disciplina']->id, $inscricao->id, '2');
        $this->lancarRecurso($c['turma']->id, $c['disciplina']->id, $inscricao->id, '8');
        app(AprovarPautaAction::class)->executar($c['turma']->id);

        [$b] = $this->boletins($c);

        $this->assertSame(['Disciplina', 'MFD', 'NE', 'MF', 'NER', 'MFR'], $b->colunas);
        $this->assertSame([$c['disciplina']->nome, '8.00', '2.00', '4.40', '8.00', '6.56'], $b->linhas[0]);
        $this->assertSame('APTO', $b->resultado);
    }

    public function test_boletim_do_pre_escolar_traz_areas_niveis_e_observacoes_do_educador(): void
    {
        [$c, $escala] = $this->cenarioQualitativo();
        $rita = $this->inscreverAluno($c, 'Rita');
        foreach ([1 => ['B', 'Participa bem'], 2 => ['B', null], 3 => ['MB', 'Evoluiu muito']] as $n => [$codigo, $obs]) {
            app(LancarQualitativaAction::class)->executar(new LancarQualitativaDTO($rita->id, $c['periodos'][$n]->id, $escala->niveis->firstWhere('codigo', $codigo)->id, $obs));
        }

        [$b] = $this->boletins($c);

        $this->assertSame('BOLETIM DE AVALIAÇÃO — EDUCAÇÃO PRÉ-ESCOLAR', $b->titulo);
        $this->assertSame(['Área', 'MT1', 'MT2', 'MT3', 'MFD'], $b->colunas);
        $this->assertSame([$c['disciplina']->nome, 'Bom', 'Bom', 'Muito Bom', 'Muito Bom'], $b->linhas[0]);
        $this->assertSame([$c['disciplina']->nome.' — I T: Participa bem', $c['disciplina']->nome.' — III T: Evoluiu muito'], $b->observacoes);
        $this->assertSame('O Educador(a)', $b->assinaturas[0]['rotulo']);
    }

    public function test_um_boletim_por_aluno_por_ordem_alfabetica_ou_so_o_pedido(): void
    {
        $c = $this->cenarioDeAvaliacao();
        $this->regra($c);
        $zeca = $this->inscreverAluno($c, 'Zeca');
        $ana = $this->inscreverAluno($c, 'Ana');
        foreach ([$zeca, $ana] as $i) {
            $this->notasCompletas($c, $i, [1 => ['8', '8'], 2 => ['8', '8'], 3 => ['8', '8']]);
        }

        $todos = $this->boletins($c);
        $so = $this->boletins($c, $zeca->matricula_id);

        $this->assertCount(2, $todos);
        $this->assertStringStartsWith('Ana', $todos[0]->identificacao[0][1]);
        $this->assertCount(1, $so);
        $this->assertStringStartsWith('Zeca', $so[0]->identificacao[0][1]);

        $outraTurma = $this->cenarioDeAvaliacao('PRI_4', 'Z');
        $deOutra = $this->inscreverAluno($outraTurma);
        $this->expectException(ValidationException::class);
        $this->boletins($c, $deOutra->matricula_id);
    }

    public function test_exporta_o_pdf_da_turma_com_uma_pagina_por_aluno_e_respeita_as_permissoes(): void
    {
        $c = $this->cenarioDeAvaliacao();
        $this->regra($c);
        foreach (['Ana', 'Rui', 'Zeca'] as $nome) {
            $this->notasCompletas($c, $this->inscreverAluno($c, $nome), [1 => ['8', '8'], 2 => ['8', '8'], 3 => ['8', '8']]);
        }
        $turma = $c['turma']->id;

        $r = $this->get("/avaliacao/pautas/{$turma}/boletins")->assertOk()->assertHeader('content-type', 'application/pdf');
        $pdf = $r->baseResponse->getFile()->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame(3, preg_match_all('/\/Type\s*\/Page\b/', $pdf));

        $matricula = \Modules\Matricula\Models\Matricula::firstOrFail()->id;
        $this->get("/avaliacao/pautas/{$turma}/boletins?matricula={$matricula}")->assertOk();

        $outra = $this->cenarioDeAvaliacao('PRI_4', 'Z');
        $this->get("/avaliacao/pautas/{$turma}/boletins?matricula=".$this->inscreverAluno($outra)->matricula_id)->assertNotFound();

        $this->actingAs($this->professorAtribuido($c))->get("/avaliacao/pautas/{$turma}/boletins")->assertForbidden();
    }

    public function test_boletim_de_turma_do_tenant_b_e_inalcancavel(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $b = $this->noTenant($outro, function () {
            $c = $this->cenarioDeAvaliacao('PRI_3', 'B');
            $this->regra($c);

            return $c;
        });

        $this->get("/avaliacao/pautas/{$b['turma']->id}/boletins")->assertNotFound();
    }
}
