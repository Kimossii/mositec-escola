<?php

namespace Modules\Avaliacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Avaliacao\Actions\GuardarRegraAvaliacaoAction;
use Modules\Avaliacao\DTO\RegraAvaliacaoDTO;
use Modules\Avaliacao\Models\AvaliacaoDocumentoConfig;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Enums\Perfil;
use Tests\TestCase;

class DocumentosHttpTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    private array $c;

    protected function setUp(): void
    {
        parent::setUp();
        Estabelecimento::current()->update(['provincia' => 'Luanda', 'municipio' => 'Camama']);
        $this->c = $this->cenarioDeAvaliacao();
        app(GuardarRegraAvaliacaoAction::class)->executar(new RegraAvaliacaoDTO($this->c['ano']->id, [$this->c['nivel']->id], 10, '5'));
        $this->notasCompletas($this->c, $this->inscreverAluno($this->c, 'Ana'), [1 => ['8', '8'], 2 => ['8', '8'], 3 => ['8', '8']]);
    }

    private function admin()
    {
        return $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com');
    }

    private function urlMini(string $formato = 'pdf'): string
    {
        return '/avaliacao/notas/exportar?'.http_build_query(['turma' => $this->c['turma']->id, 'disciplina' => $this->c['disciplina']->id, 'formato' => $formato]);
    }

    public function test_admin_exporta_a_pauta_em_pdf_e_em_excel(): void
    {
        $admin = $this->admin();

        $pdf = $this->actingAs($admin)->get("/avaliacao/pautas/{$this->c['turma']->id}/exportar?formato=pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->baseResponse->getFile()->getContent());
        $this->assertStringContainsString('pauta-final-', $pdf->baseResponse->headers->get('content-disposition'));

        $xlsx = $this->actingAs($admin)->get("/avaliacao/pautas/{$this->c['turma']->id}/exportar?formato=excel")->assertOk();
        $this->assertStringStartsWith('PK', $xlsx->baseResponse->getFile()->getContent());
        $this->assertStringContainsString('.xlsx', $xlsx->baseResponse->headers->get('content-disposition'));
    }

    public function test_formato_invalido_volta_com_erro(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get("/avaliacao/pautas/{$this->c['turma']->id}/exportar?formato=doc")->assertSessionHasErrors('formato');
        $this->actingAs($admin)->get($this->urlMini('csv'))->assertSessionHasErrors('formato');
    }

    public function test_professor_nao_exporta_a_pauta_da_turma_mas_exporta_a_sua_mini_pauta(): void
    {
        $professor = $this->professorAtribuido($this->c);

        $this->actingAs($professor)->get("/avaliacao/pautas/{$this->c['turma']->id}/exportar?formato=pdf")->assertForbidden();
        $this->actingAs($professor)->get($this->urlMini('pdf'))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($professor)->get($this->urlMini('excel'))->assertOk();

        $outro = $this->criarUtilizadorComPerfil(Perfil::PROFESSOR, 'outro@example.com');
        $this->actingAs($outro)->get($this->urlMini('pdf'))->assertForbidden();
    }

    public function test_sem_sessao_nao_exporta(): void
    {
        $this->get("/avaliacao/pautas/{$this->c['turma']->id}/exportar?formato=pdf")->assertRedirect();
        $this->get($this->urlMini())->assertRedirect();
    }

    public function test_mini_pauta_qualitativa_exporta_em_pdf_e_em_excel(): void
    {
        [$q] = $this->cenarioQualitativo('Q');
        $admin = $this->admin();
        $url = fn (string $formato) => '/avaliacao/notas/exportar?'.http_build_query(['turma' => $q['turma']->id, 'disciplina' => $q['disciplina']->id, 'formato' => $formato]);

        $this->actingAs($admin)->get($url('pdf'))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($admin)->get($url('excel'))->assertOk();
    }

    public function test_configuracao_dos_documentos_guarda_e_mostra_o_cabecalho(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/avaliacao/configuracao/documentos', [
            'linhas_extra' => "Direcção Municipal da Educação\nSecção de Educação e Ensino", 'lema' => 'Linguagem do amor', 'visto_cargo' => 'Directora Municipal', 'visto_nome' => 'Yolande',
        ])->assertRedirect();

        $this->actingAs($admin)->get('/avaliacao/configuracao')->assertInertia(fn (Assert $p) => $p
            ->where('documentos.lema', 'Linguagem do amor')
            ->where('documentos.visto_nome', 'Yolande')
            ->where('cabecalho_linhas.4', 'DIRECÇÃO MUNICIPAL DA EDUCAÇÃO')
            ->where('cabecalho_linhas.2', 'GOVERNO DA PROVÍNCIA DE LUANDA'));
    }

    public function test_configuracao_dos_documentos_recusa_linhas_a_mais_e_o_professor_nao_configura(): void
    {
        $this->actingAs($this->admin())->post('/avaliacao/configuracao/documentos', ['linhas_extra' => implode("\n", range(1, 7))])->assertSessionHasErrors('linhas_extra');

        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::PROFESSOR, 'prof@example.com'))->post('/avaliacao/configuracao/documentos', ['lema' => 'x'])->assertForbidden();
        $this->assertSame(0, AvaliacaoDocumentoConfig::count());
    }

    public function test_a_mini_pauta_mostra_npt_so_na_7_a_9_classe(): void
    {
        $c7 = $this->cenarioDeAvaliacao('SEC_7', 'S');
        app(GuardarRegraAvaliacaoAction::class)->executar(new RegraAvaliacaoDTO($c7['ano']->id, [$c7['nivel']->id], 20, '10'));
        $admin = $this->admin();
        $url = fn (array $c) => '/avaliacao/notas?'.http_build_query(['turma' => $c['turma']->id, 'disciplina' => $c['disciplina']->id]);

        $this->actingAs($admin)->get($url($c7))->assertInertia(fn (Assert $p) => $p->where('pauta.rotulo_pt', 'NPT'));
        $this->actingAs($admin)->get($url($this->c))->assertInertia(fn (Assert $p) => $p->where('pauta.rotulo_pt', 'PT'));
    }

    public function test_exportacoes_e_cabecalho_do_tenant_b_sao_inalcancaveis(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $b = $this->noTenant($outro, function () {
            $c = $this->cenarioDeAvaliacao('PRI_3', 'B');
            app(GuardarRegraAvaliacaoAction::class)->executar(new RegraAvaliacaoDTO($c['ano']->id, [$c['nivel']->id], 10, '5'));
            $this->guardarConfigDocumentos('Linha só da escola B', 'Lema B', null, null);

            return $c;
        });
        $admin = $this->admin();

        $this->actingAs($admin)->get("/avaliacao/pautas/{$b['turma']->id}/exportar?formato=pdf")->assertNotFound();
        $this->actingAs($admin)->get('/avaliacao/notas/exportar?'.http_build_query(['turma' => $b['turma']->id, 'disciplina' => $b['disciplina']->id, 'formato' => 'pdf']))->assertNotFound();

        $this->assertSame(0, AvaliacaoDocumentoConfig::count());
        $this->actingAs($admin)->get('/avaliacao/configuracao')->assertInertia(fn (Assert $p) => $p->where('documentos.lema', null)->where('cabecalho_linhas', fn ($l) => ! str_contains(json_encode($l), 'ESCOLA B')));
    }
}
