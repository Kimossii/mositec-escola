<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Usuario\Database\Seeders\TipoDocumentoSeeder;
use Modules\Usuario\DTO\DocumentoPessoaDTO;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\DocumentoPessoa;
use Modules\Usuario\Models\TipoDocumento;
use Modules\Usuario\Services\GestaoDocumentoPessoaService;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class GestaoDocumentoPessoaServiceTest extends TestCase
{
    use RefreshDatabase;

    private function criarPessoa(): DadosPessoa
    {
        return DadosPessoa::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
    }

    public function test_listar_devolve_os_documentos_da_pessoa_com_tipo_carregado(): void
    {
        $this->seed(TipoDocumentoSeeder::class);
        $pessoa = $this->criarPessoa();
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();
        DocumentoPessoa::create([
            'dados_pessoa_id' => $pessoa->id,
            'tipo_documento_id' => $tipo->id,
            'nome_original' => 'bi.pdf',
            'caminho' => 'x',
            'mime_type' => 'application/pdf',
            'tamanho' => 10,
        ]);

        $documentos = app(GestaoDocumentoPessoaService::class)->listar($pessoa);

        $this->assertCount(1, $documentos);
        $this->assertTrue($documentos->first()->relationLoaded('tipoDocumento'));
    }

    public function test_adicionar_cria_documento_via_action(): void
    {
        Storage::fake('documentos');
        $this->seed(TipoDocumentoSeeder::class);
        $pessoa = $this->criarPessoa();
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();
        $dto = new DocumentoPessoaDTO($tipo->id, 'BI0001', null, null, null);

        $documento = app(GestaoDocumentoPessoaService::class)->adicionar($pessoa, $dto, UploadedFile::fake()->create('bi.pdf', 100, 'application/pdf'));

        $this->assertInstanceOf(DocumentoPessoa::class, $documento);
        $this->assertSame(1, DocumentoPessoa::count());
    }

    public function test_remover_faz_soft_delete_via_action(): void
    {
        $this->seed(TipoDocumentoSeeder::class);
        $pessoa = $this->criarPessoa();
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();
        $documento = DocumentoPessoa::create([
            'dados_pessoa_id' => $pessoa->id,
            'tipo_documento_id' => $tipo->id,
            'nome_original' => 'bi.pdf',
            'caminho' => 'x',
            'mime_type' => 'application/pdf',
            'tamanho' => 10,
        ]);

        app(GestaoDocumentoPessoaService::class)->remover($documento);

        $this->assertSoftDeleted('documentos_pessoas', ['id' => $documento->id]);
    }

    public function test_download_devolve_streamed_response_do_disco_privado(): void
    {
        Storage::fake('documentos');
        $this->seed(TipoDocumentoSeeder::class);
        $pessoa = $this->criarPessoa();
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();
        $caminho = UploadedFile::fake()->create('bi.pdf', 100, 'application/pdf')->store('documentos-pessoas/' . $pessoa->id, 'documentos');
        $documento = DocumentoPessoa::create([
            'dados_pessoa_id' => $pessoa->id,
            'tipo_documento_id' => $tipo->id,
            'nome_original' => 'bi.pdf',
            'caminho' => $caminho,
            'mime_type' => 'application/pdf',
            'tamanho' => 10,
        ]);

        $resposta = app(GestaoDocumentoPessoaService::class)->download($documento);

        $this->assertInstanceOf(StreamedResponse::class, $resposta);
    }

    public function test_visualizar_devolve_streamed_response_inline(): void
    {
        Storage::fake('documentos');
        $this->seed(TipoDocumentoSeeder::class);
        $pessoa = $this->criarPessoa();
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();
        $caminho = UploadedFile::fake()->create('bi.pdf', 100, 'application/pdf')->store('documentos-pessoas/' . $pessoa->id, 'documentos');
        $documento = DocumentoPessoa::create([
            'dados_pessoa_id' => $pessoa->id,
            'tipo_documento_id' => $tipo->id,
            'nome_original' => 'bi.pdf',
            'caminho' => $caminho,
            'mime_type' => 'application/pdf',
            'tamanho' => 10,
        ]);

        $resposta = app(GestaoDocumentoPessoaService::class)->visualizar($documento);

        $this->assertInstanceOf(StreamedResponse::class, $resposta);
        $this->assertStringStartsWith('inline', $resposta->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $resposta->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', $resposta->headers->get('Content-Security-Policy'));
    }

    public function test_visualizar_forca_download_quando_conteudo_real_nao_esta_na_allowlist(): void
    {
        Storage::fake('documentos');
        Storage::disk('documentos')->put(
            'documentos-pessoas/1/disfarcado.pdf',
            '<html><body><script>alert(document.cookie)</script></body></html>',
        );
        $this->seed(TipoDocumentoSeeder::class);
        $pessoa = $this->criarPessoa();
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();
        $documento = DocumentoPessoa::create([
            'dados_pessoa_id' => $pessoa->id,
            'tipo_documento_id' => $tipo->id,
            'nome_original' => 'disfarcado.pdf',
            'caminho' => 'documentos-pessoas/1/disfarcado.pdf',
            'mime_type' => 'application/pdf',
            'tamanho' => 10,
        ]);

        $resposta = app(GestaoDocumentoPessoaService::class)->visualizar($documento);

        $this->assertStringStartsWith('attachment', $resposta->headers->get('Content-Disposition'));
    }

    public function test_tipos_disponiveis_devolve_apenas_tipos_activos(): void
    {
        $this->seed(TipoDocumentoSeeder::class);
        $inactivo = TipoDocumento::where('slug', 'bi')->firstOrFail();
        $inactivo->update(['estado' => 0]);

        $tipos = app(GestaoDocumentoPessoaService::class)->tiposDisponiveis();

        $this->assertCount(5, $tipos);
        $this->assertFalse($tipos->contains('id', $inactivo->id));
    }
}
