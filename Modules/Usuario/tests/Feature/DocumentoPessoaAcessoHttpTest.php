<?php

namespace Modules\Usuario\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Permissao\Enums\Modulo as ModuloEnum;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\UserPermissao;
use Modules\Usuario\Actions\CriarDocumentoPessoaAction;
use Modules\Usuario\Database\Seeders\TipoDocumentoSeeder;
use Modules\Usuario\DTO\DocumentoPessoaDTO;
use Modules\Usuario\Models\AcessoDocumentoPessoa;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\DocumentoPessoa;
use Modules\Usuario\Models\TipoDocumento;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class DocumentoPessoaAcessoHttpTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    private array $cenario;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documentos');
        $this->seed(TipoDocumentoSeeder::class);
        $this->cenario = $this->cenarioDeAvaliacao();
    }

    private function documentoDe(DadosPessoa $pessoa): DocumentoPessoa
    {
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();
        $dto = new DocumentoPessoaDTO(tipo_documento_id: $tipo->id, numero_documento: '1', data_emissao: null, data_validade: null, observacoes: null);

        return app(CriarDocumentoPessoaAction::class)->executar($pessoa, $dto, UploadedFile::fake()->create('bi.pdf', 10, 'application/pdf'));
    }

    private function admin(): User
    {
        return tap($this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'adm@example.com'), fn ($u) => $this->actingAs($u));
    }

    /** Professor com atribuição na turma do cenário e a permissão `documento-pessoa.ver` por override. */
    private function professorComPermissao(): User
    {
        $prof = $this->professorAtribuido($this->cenario);
        UserPermissao::create([
            'users_id' => $prof->id,
            'modulo_id' => Modulo::where('nome', ModuloEnum::DOCUMENTO_PESSOA->value)->firstOrFail()->id,
            'acao_id' => Acao::where('nome', 'ver')->firstOrFail()->id,
            'permitido' => true,
        ]);
        $this->actingAs($prof);

        return $prof;
    }

    private function alunoDaTurma(): DadosPessoa
    {
        return $this->inscreverAluno($this->cenario)->matricula->aluno->dadosPessoa;
    }

    private function alunoDeOutraTurma(): DadosPessoa
    {
        return $this->inscreverAluno($this->cenarioDeAvaliacao('PRI_4', 'B'), 'Outro')->matricula->aluno->dadosPessoa;
    }

    public function test_download_permitido_cria_registo_download(): void
    {
        $admin = $this->admin();
        $doc = $this->documentoDe($this->alunoDaTurma());

        $this->get("/documentos-pessoa/{$doc->id}/download")->assertOk();

        $a = AcessoDocumentoPessoa::firstOrFail();
        $this->assertSame('download', $a->acao->value);
        $this->assertSame($admin->id, $a->user_id);
        $this->assertSame($doc->id, $a->documento_pessoa_id);
        $this->assertSame($doc->dados_pessoa_id, $a->dados_pessoa_id);
    }

    public function test_visualizar_regista_uma_so_linha(): void
    {
        $this->admin();
        $doc = $this->documentoDe($this->alunoDaTurma());

        $this->get("/documentos-pessoa/{$doc->id}/visualizar")->assertOk();

        $this->assertSame(1, AcessoDocumentoPessoa::count());
        $this->assertContains(AcessoDocumentoPessoa::first()->acao->value, ['visualizar', 'download']);
    }

    public function test_listagens_criam_registo_listar(): void
    {
        $this->admin();
        $pessoa = $this->alunoDaTurma();

        $this->getJson("/dados-pessoais/{$pessoa->id}/documentos")->assertOk();
        $this->getJson("/dados-pessoais/{$pessoa->id}/documentos-inativos")->assertOk();

        $this->assertSame(2, AcessoDocumentoPessoa::where('acao', 'listar')->whereNull('documento_pessoa_id')->count());
    }

    public function test_professor_dentro_do_ambito_acede(): void
    {
        $this->professorComPermissao();
        $pessoa = $this->alunoDaTurma();
        $doc = $this->documentoDe($pessoa);

        $this->getJson("/dados-pessoais/{$pessoa->id}/documentos")->assertOk();
        $this->get("/documentos-pessoa/{$doc->id}/download")->assertOk();
    }

    public function test_professor_fora_do_ambito_recebe_403_e_regista_negado_nas_quatro_rotas(): void
    {
        $this->professorComPermissao();
        $pessoa = $this->alunoDeOutraTurma();
        $doc = $this->documentoDe($pessoa);

        $this->getJson("/dados-pessoais/{$pessoa->id}/documentos")->assertForbidden();
        $this->getJson("/dados-pessoais/{$pessoa->id}/documentos-inativos")->assertForbidden();
        $this->get("/documentos-pessoa/{$doc->id}/download")->assertForbidden();
        $this->get("/documentos-pessoa/{$doc->id}/visualizar")->assertForbidden();

        $this->assertSame(4, AcessoDocumentoPessoa::where('acao', 'negado')->count());
        $this->assertSame(0, AcessoDocumentoPessoa::where('acao', '!=', 'negado')->count());
    }

    public function test_documento_de_outro_tenant_devolve_404_sem_registo(): void
    {
        $this->admin();
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $docB = $this->noTenant($outro, function () {
            $this->seed(TipoDocumentoSeeder::class);

            return $this->documentoDe(DadosPessoa::create(['nome_completo' => 'B', 'numero_identificacao' => 'BIB', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]));
        });

        $this->get("/documentos-pessoa/{$docB->id}/download")->assertNotFound();
        $this->assertSame(0, AcessoDocumentoPessoa::count());
    }

    public function test_rotas_de_leitura_tem_throttle(): void
    {
        foreach (['documentos-pessoa.index', 'documentos-pessoa.inativos', 'documentos-pessoa.download', 'documentos-pessoa.visualizar'] as $nome) {
            $this->assertContains('throttle:operacao-pesada', Route::getRoutes()->getByName($nome)->gatherMiddleware(), $nome);
        }
    }

    private function abrirPessoasDistintas(int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            $p = DadosPessoa::create(['nome_completo' => "P{$i}", 'numero_identificacao' => "BI-V-{$i}", 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
            $resposta = $this->getJson("/dados-pessoais/{$p->id}/documentos");
            if ($i <= 10) {
                $resposta->assertOk();
            }
        }
    }

    public function test_onze_pessoas_distintas_em_10_minutos_devolvem_429_e_ficam_registadas(): void
    {
        $this->admin();
        $this->abrirPessoasDistintas(11);

        $ultima = AcessoDocumentoPessoa::orderByDesc('id')->firstOrFail();
        $this->assertSame('negado', $ultima->acao->value);
        $this->assertSame(10, AcessoDocumentoPessoa::where('acao', 'listar')->count());
    }

    public function test_repetir_a_mesma_pessoa_nao_conta(): void
    {
        $this->admin();
        $pessoa = $this->alunoDaTurma();

        for ($i = 0; $i < 15; $i++) {
            $this->getJson("/dados-pessoais/{$pessoa->id}/documentos")->assertOk();
        }
    }

    public function test_janela_expira_apos_10_minutos(): void
    {
        $this->admin();
        $this->abrirPessoasDistintas(10);
        Carbon::setTestNow(now()->addMinutes(11));

        $nova = DadosPessoa::create(['nome_completo' => 'Nova', 'numero_identificacao' => 'BI-NOVA', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $this->getJson("/dados-pessoais/{$nova->id}/documentos")->assertOk();
        Carbon::setTestNow();
    }
}
