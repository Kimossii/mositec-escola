<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Enums\Estado;
use Modules\Usuario\Actions\AlternarEstadoDocumentoPessoaAction;
use Modules\Usuario\Database\Seeders\TipoDocumentoSeeder;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\DocumentoPessoa;
use Modules\Usuario\Models\TipoDocumento;
use Tests\TestCase;

class AlternarEstadoDocumentoPessoaActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_alterna_de_ativo_para_inativo_e_vice_versa(): void
    {
        $this->seed(TipoDocumentoSeeder::class);
        $pessoa = DadosPessoa::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();
        $documento = DocumentoPessoa::create([
            'dados_pessoa_id' => $pessoa->id,
            'tipo_documento_id' => $tipo->id,
            'nome_original' => 'bi.pdf',
            'caminho' => 'x',
            'mime_type' => 'application/pdf',
            'tamanho' => 10,
        ]);
        $action = app(AlternarEstadoDocumentoPessoaAction::class);

        $inativo = $action->executar($documento);
        $this->assertSame(Estado::INATIVO->value, $inativo->estado);

        $ativo = $action->executar($inativo);
        $this->assertSame(Estado::ATIVO->value, $ativo->estado);
    }

    public function test_reativar_documento_desativa_sibling_ativo(): void
    {
        $this->seed(TipoDocumentoSeeder::class);
        $pessoa = DadosPessoa::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();

        // Create first document (ATIVO by default)
        $documentoAtivo = DocumentoPessoa::create([
            'dados_pessoa_id' => $pessoa->id,
            'tipo_documento_id' => $tipo->id,
            'nome_original' => 'bi-old.pdf',
            'caminho' => 'x',
            'mime_type' => 'application/pdf',
            'tamanho' => 10,
        ]);

        // Create second document (INATIVO)
        $documentoInativo = DocumentoPessoa::create([
            'dados_pessoa_id' => $pessoa->id,
            'tipo_documento_id' => $tipo->id,
            'nome_original' => 'bi-new.pdf',
            'caminho' => 'y',
            'mime_type' => 'application/pdf',
            'tamanho' => 10,
            'estado' => Estado::INATIVO->value,
        ]);

        $action = app(AlternarEstadoDocumentoPessoaAction::class);

        // Reactivate the inactive document
        $reativado = $action->executar($documentoInativo);

        // Assert reactivated document is now ATIVO
        $this->assertSame(Estado::ATIVO->value, $reativado->estado);

        // Assert the previously-active sibling is now INATIVO
        $documentoAtivoRefresh = $documentoAtivo->fresh();
        $this->assertSame(Estado::INATIVO->value, $documentoAtivoRefresh->estado);
    }
}
