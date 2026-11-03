<?php

namespace Modules\Avaliacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\Actions\AprovarPautaAction;
use Modules\Avaliacao\Actions\GuardarConfigDocumentosAction;
use Modules\Avaliacao\Actions\GuardarRegraAvaliacaoAction;
use Modules\Avaliacao\Actions\LancarExameAction;
use Modules\Avaliacao\Actions\LancarQualitativaAction;
use Modules\Avaliacao\DTO\LancarQualitativaDTO;
use Modules\Avaliacao\DTO\RegraAvaliacaoDTO;
use Modules\Avaliacao\Documentos\DocumentoPauta;
use Modules\Avaliacao\Documentos\EscritorPautaPdf;
use Modules\Avaliacao\Documentos\EscritorPautaXlsx;
use Modules\Avaliacao\Services\CabecalhoDocumentoService;
use Modules\Avaliacao\Services\MiniPautaDocumentoService;
use Modules\Avaliacao\Services\PautaDocumentoService;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Enums\Perfil;
use Tests\TestCase;
use ZipArchive;

class DocumentosPautaTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Estabelecimento::current()->update([
            'nome' => 'Complexo Escolar Privado Vladmir Edson', 'provincia' => 'Luanda', 'municipio' => 'Camama',
            'responsavel_nome' => 'Glória Mendonça', 'responsavel_cargo' => 'A Directora',
        ]);
        $this->travelTo('2026-03-15 10:00:00');
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com'));
    }

    private function regra(array $c): void
    {
        app(GuardarRegraAvaliacaoAction::class)->executar(new RegraAvaliacaoDTO($c['ano']->id, $c['nivel']->id, 10, '5'));
    }

    private function pautaDe(array $c): DocumentoPauta
    {
        return app(PautaDocumentoService::class)->paraTurma($c['turma']->id);
    }

    public function test_cabecalho_vem_da_escola_e_as_linhas_extra_entram_antes_do_nome(): void
    {
        $sem = app(CabecalhoDocumentoService::class)->dados();
        $this->assertSame(['REPÚBLICA DE ANGOLA', 'MINISTÉRIO DA EDUCAÇÃO', 'GOVERNO DA PROVÍNCIA DE LUANDA', 'ADMINISTRAÇÃO MUNICIPAL DE CAMAMA', 'COMPLEXO ESCOLAR PRIVADO VLADMIR EDSON'], $sem['linhas']);
        $this->assertNull($sem['visto']);

        app(GuardarConfigDocumentosAction::class)->executar("Direcção Municipal da Educação\n\n  Secção de Educação e Ensino ", 'Linguagem do amor', 'Directora Municipal', 'Yolande Maurício');
        $com = app(CabecalhoDocumentoService::class)->dados();

        $this->assertSame(['ADMINISTRAÇÃO MUNICIPAL DE CAMAMA', 'DIRECÇÃO MUNICIPAL DA EDUCAÇÃO', 'SECÇÃO DE EDUCAÇÃO E ENSINO', 'COMPLEXO ESCOLAR PRIVADO VLADMIR EDSON'], array_slice($com['linhas'], 3));
        $this->assertSame('Linguagem do amor', $com['lema']);
        $this->assertSame(['nome' => 'Yolande Maurício', 'cargo' => 'Directora Municipal'], $com['visto']);
        $this->assertSame(['nome' => 'Glória Mendonça', 'cargo' => 'A Directora'], $com['responsavel']);
    }

    public function test_config_de_documentos_limita_linhas_e_tamanho_e_actualiza_a_mesma_linha(): void
    {
        $acao = app(GuardarConfigDocumentosAction::class);

        $acao->executar('A', null, null, null);
        $acao->executar('B', null, null, null);
        $this->assertSame(1, \Modules\Avaliacao\Models\AvaliacaoDocumentoConfig::count());

        foreach ([implode("\n", range(1, 7)), str_repeat('x', 121)] as $invalido) {
            try {
                $acao->executar($invalido, null, null, null);
                $this->fail('Esperava ValidationException.');
            } catch (ValidationException) {
            }
        }
    }

    public function test_pauta_numerica_tem_sexo_mt_mfd_e_resultado_final(): void
    {
        $c = $this->cenarioDeAvaliacao();
        $this->regra($c);
        $ana = $this->inscreverAluno($c, 'Ana');
        $ana->matricula->aluno->dadosPessoa->update(['sexo' => 2]);
        $this->notasCompletas($c, $ana, [1 => ['5.33', '7'], 2 => ['6', '7'], 3 => ['8', '9']]);

        $d = $this->pautaDe($c);

        $this->assertSame('PAUTA FINAL (PROVISÓRIA)', $d->titulo);
        $this->assertSame(['N.º', 'Nome do aluno', 'Sexo'], $d->fixas);
        $this->assertSame(['MT1', 'MT2', 'MT3', 'MFD'], $d->grupos[0]['colunas']);
        $this->assertSame(['Resultado final'], $d->finais);
        $this->assertSame(['1', $ana->matricula->aluno->dadosPessoa->nome_completo, 'F', '6.17', '6.50', '8.50', '7.06', 'TRANSITA'], $d->linhas[0]);
        $this->assertSame(['CLASSE', 'Classe PRI_3'], $d->meta[0]);
        $this->assertContains(['ANO LECTIVO', '2026A'], $d->meta);
        $this->assertNotNull($d->nota);
        $this->assertSame('A Directora', $d->assinaturas[0]['rotulo']);
        $this->assertSame('Glória Mendonça', $d->assinaturas[0]['nome']);
        $this->assertSame($d->totalDeColunas(), count($d->linhas[0]));
    }

    public function test_pauta_definitiva_perde_a_marca_de_provisoria(): void
    {
        $c = $this->cenarioDeAvaliacao();
        $this->regra($c);
        $this->notasCompletas($c, $this->inscreverAluno($c), [1 => ['8', '8'], 2 => ['8', '8'], 3 => ['8', '8']]);
        app(AprovarPautaAction::class)->executar($c['turma']->id);

        $d = $this->pautaDe($c);

        $this->assertSame('PAUTA FINAL', $d->titulo);
        $this->assertNull($d->nota);
    }

    public function test_pauta_com_exame_mostra_mfd_ne_mf_e_apto(): void
    {
        $c = $this->cenarioDeAvaliacao('PRI_6');
        $this->regra($c);
        $inscricao = $this->inscreverAluno($c);
        $this->notasCompletas($c, $inscricao, [1 => ['8', '8'], 2 => ['8', '8'], 3 => ['8', '8']]);
        app(LancarExameAction::class)->executar($c['turma']->id, $c['disciplina']->id, $inscricao->id, '10');

        $d = $this->pautaDe($c);

        $this->assertSame(['MFD', 'NE', 'MF'], $d->grupos[0]['colunas']);
        $this->assertSame(['8.00', '10.00', '9.20', 'APTO'], array_slice($d->linhas[0], 3));
    }

    public function test_pauta_qualitativa_sem_sexo_e_com_observacao(): void
    {
        [$c, $escala] = $this->cenarioQualitativo();
        $inscricao = $this->inscreverAluno($c, 'Rita');
        foreach ([1 => 'B', 2 => 'B', 3 => 'MB'] as $n => $codigo) {
            app(LancarQualitativaAction::class)->executar(new LancarQualitativaDTO($inscricao->id, $c['periodos'][$n]->id, $escala->niveis->firstWhere('codigo', $codigo)->id));
        }

        $d = $this->pautaDe($c);

        $this->assertSame(['N.º', 'Nome do aluno'], $d->fixas);
        $this->assertSame(['Observação'], $d->finais);
        $this->assertSame(['1', $inscricao->matricula->aluno->dadosPessoa->nome_completo, 'Bom', 'Bom', 'Muito Bom', 'Muito Bom', 'TRANSITA'], $d->linhas[0]);
    }

    public function test_mini_pauta_tem_trimestres_mfd_e_a_prova_chama_se_npt_so_na_7_a_9(): void
    {
        $c = $this->cenarioDeAvaliacao('SEC_7');
        $this->regra($c);
        $ana = $this->inscreverAluno($c, 'Ana');
        $this->notasCompletas($c, $ana, [1 => ['5.33', '7'], 2 => ['6', '7'], 3 => ['8', '9']]);

        $d = app(MiniPautaDocumentoService::class)->paraTurmaDisciplina($c['turma']->id, $c['disciplina']->id);

        $this->assertSame('MINI-PAUTA', $d->titulo);
        $this->assertSame(['MAC', 'NPT', 'MT1'], $d->grupos[0]['colunas']);
        $this->assertSame(['MFD'], $d->grupos[3]['colunas']);
        $this->assertSame(['1', $ana->matricula->aluno->dadosPessoa->nome_completo, '5.33', '7.00', '6.17', '6.00', '7.00', '6.50', '8.00', '9.00', '8.50', '7.06', ''], $d->linhas[0]);
        $this->assertSame('PT', MiniPautaDocumentoService::rotuloDaProva('PRI_3'));
    }

    public function test_mini_pauta_do_exame_traz_ne_e_mf_e_a_qualitativa_nao_exporta(): void
    {
        $c = $this->cenarioDeAvaliacao('PRI_6');
        $this->regra($c);
        $inscricao = $this->inscreverAluno($c);
        $this->notasCompletas($c, $inscricao, [1 => ['8', '8'], 2 => ['8', '8'], 3 => ['8', '8']]);
        app(LancarExameAction::class)->executar($c['turma']->id, $c['disciplina']->id, $inscricao->id, '10');

        $d = app(MiniPautaDocumentoService::class)->paraTurmaDisciplina($c['turma']->id, $c['disciplina']->id);
        $this->assertSame(['MFD', 'NE', 'MF'], $d->grupos[3]['colunas']);
        $this->assertSame(['8.00', '10.00', '9.20', ''], array_slice($d->linhas[0], -4));

        [$q] = $this->cenarioQualitativo('Q');
        $this->expectException(ValidationException::class);
        app(MiniPautaDocumentoService::class)->paraTurmaDisciplina($q['turma']->id, $q['disciplina']->id);
    }

    public function test_excel_tem_celulas_unidas_cabecalho_e_texto_escapado(): void
    {
        $d = new DocumentoPauta('PAUTA FINAL', ['REPÚBLICA DE ANGOLA'], null, null, [['CLASSE', '9.ª']], ['N.º', 'Nome do aluno'],
            [['rotulo' => 'MATEMÁTICA', 'colunas' => ['MFD', 'NE', 'MF']]], ['Resultado final'], [['1', 'Ana <b> & Filho', '10', '8', '9.2', 'APTO']],
            [['rotulo' => 'A Directora', 'nome' => 'Glória', 'cargo' => null]]);
        $caminho = tempnam(sys_get_temp_dir(), 'xl');

        (new EscritorPautaXlsx)->escrever($d, $caminho);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($caminho));
        $folha = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('<mergeCell ref="C', $folha);
        $this->assertStringContainsString('REPÚBLICA DE ANGOLA', $folha);
        $this->assertStringContainsString('Ana &lt;b&gt; &amp; Filho', $folha);
        $this->assertStringNotContainsString('Ana <b>', $folha);
        $this->assertNotFalse($zip->getFromName('xl/styles.xml'));
        $zip->close();
        unlink($caminho);
    }

    public function test_pdf_e_gerado(): void
    {
        $d = new DocumentoPauta('MINI-PAUTA', ['REPÚBLICA DE ANGOLA'], 'Lema', null, [['CLASSE', '9.ª']], ['N.º', 'Nome'],
            [['rotulo' => 'I TRIMESTRE', 'colunas' => ['MAC', 'NPT', 'MT1']]], ['Obs.'], [['1', 'Ana', '7', '8', '7.5', '']],
            [['rotulo' => 'O Professor', 'nome' => null, 'cargo' => null]], 'Nota');
        $caminho = tempnam(sys_get_temp_dir(), 'pdf');

        (new EscritorPautaPdf)->escrever($d, $caminho);

        $this->assertStringStartsWith('%PDF', file_get_contents($caminho));
        unlink($caminho);
    }
}
