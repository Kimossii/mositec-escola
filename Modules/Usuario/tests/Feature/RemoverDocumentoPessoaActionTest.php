<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Enums\Estado;
use Modules\Usuario\Actions\RemoverDocumentoPessoaAction;
use Modules\Usuario\Database\Seeders\TipoDocumentoSeeder;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\DocumentoPessoa;
use Modules\Usuario\Models\TipoDocumento;
use Tests\TestCase;

class RemoverDocumentoPessoaActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_remove_por_soft_delete_sem_alterar_estado(): void
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

        app(RemoverDocumentoPessoaAction::class)->executar($documento);

        $this->assertSoftDeleted('documentos_pessoas', ['id' => $documento->id]);
        $comTrashed = DocumentoPessoa::withTrashed()->find($documento->id);
        $this->assertSame(Estado::ATIVO->value, $comTrashed->estado);
    }
}
