<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Enums\Estado;
use Modules\Usuario\Actions\CriarDocumentoPessoaAction;
use Modules\Usuario\Database\Seeders\TipoDocumentoSeeder;
use Modules\Usuario\DTO\DocumentoPessoaDTO;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\DocumentoPessoa;
use Modules\Usuario\Models\TipoDocumento;
use Tests\TestCase;

class CriarDocumentoPessoaActionTest extends TestCase
{
    use RefreshDatabase;

    private function criarPessoa(string $numeroIdentificacao = 'BI0001'): DadosPessoa
    {
        return DadosPessoa::create([
            'nome_completo' => 'Ana Silva',
            'numero_identificacao' => $numeroIdentificacao,
            'tipo_pessoa' => DadosPessoa::TIPO_ALUNO,
        ]);
    }

    public function test_cria_documento_e_guarda_ficheiro_no_disco_privado(): void
    {
        Storage::fake('documentos');
        $this->seed(TipoDocumentoSeeder::class);
        $pessoa = $this->criarPessoa();
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();

        $dto = new DocumentoPessoaDTO(
            tipo_documento_id: $tipo->id,
            numero_documento: '0012345LA042',
            data_emissao: '2020-01-10',
            data_validade: '2030-01-10',
            observacoes: null,
        );
        $ficheiro = UploadedFile::fake()->create('bi-frente.pdf', 200, 'application/pdf');

        $documento = app(CriarDocumentoPessoaAction::class)->executar($pessoa, $dto, $ficheiro);

        $this->assertInstanceOf(DocumentoPessoa::class, $documento);
        $this->assertSame($pessoa->id, $documento->dados_pessoa_id);
        $this->assertSame($tipo->id, $documento->tipo_documento_id);
        $this->assertSame('0012345LA042', $documento->numero_documento);
        $this->assertSame('bi-frente.pdf', $documento->nome_original);
        $this->assertSame('application/pdf', $documento->mime_type);
        $this->assertSame(Estado::ATIVO->value, $documento->estado);
        Storage::disk('documentos')->assertExists($documento->caminho);
    }

    public function test_novo_documento_do_mesmo_tipo_desactiva_o_anterior_sem_apagar(): void
    {
        Storage::fake('documentos');
        $this->seed(TipoDocumentoSeeder::class);
        $pessoa = $this->criarPessoa();
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();
        $action = app(CriarDocumentoPessoaAction::class);

        $primeiro = $action->executar(
            $pessoa,
            new DocumentoPessoaDTO($tipo->id, 'BI-ANTIGO', null, null, null),
            UploadedFile::fake()->create('antigo.pdf', 100, 'application/pdf'),
        );

        $segundo = $action->executar(
            $pessoa,
            new DocumentoPessoaDTO($tipo->id, 'BI-NOVO', null, null, null),
            UploadedFile::fake()->create('novo.pdf', 100, 'application/pdf'),
        );

        $this->assertSame(Estado::INATIVO->value, $primeiro->fresh()->estado);
        $this->assertSame(Estado::ATIVO->value, $segundo->fresh()->estado);
        $this->assertNull($primeiro->fresh()->deleted_at);
        Storage::disk('documentos')->assertExists($primeiro->caminho);
        Storage::disk('documentos')->assertExists($segundo->caminho);
    }

    public function test_documento_de_outra_pessoa_do_mesmo_tipo_nao_e_afectado(): void
    {
        Storage::fake('documentos');
        $this->seed(TipoDocumentoSeeder::class);
        $pessoa1 = $this->criarPessoa('BI0001');
        $pessoa2 = $this->criarPessoa('BI0002');
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();
        $action = app(CriarDocumentoPessoaAction::class);

        $documentoPessoa1 = $action->executar(
            $pessoa1,
            new DocumentoPessoaDTO($tipo->id, 'BI0001', null, null, null),
            UploadedFile::fake()->create('a.pdf', 100, 'application/pdf'),
        );
        $action->executar(
            $pessoa2,
            new DocumentoPessoaDTO($tipo->id, 'BI0002', null, null, null),
            UploadedFile::fake()->create('b.pdf', 100, 'application/pdf'),
        );

        $this->assertSame(Estado::ATIVO->value, $documentoPessoa1->fresh()->estado);
    }
}
