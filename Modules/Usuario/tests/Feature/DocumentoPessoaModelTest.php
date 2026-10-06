<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Usuario\Database\Seeders\TipoDocumentoSeeder;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\DocumentoPessoa;
use Modules\Usuario\Models\TipoDocumento;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class DocumentoPessoaModelTest extends TestCase
{
    use RefreshDatabase;

    private function criarPessoa(): DadosPessoa
    {
        return DadosPessoa::create([
            'nome_completo' => 'Ana Silva',
            'numero_identificacao' => 'BI0001',
            'tipo_pessoa' => DadosPessoa::TIPO_ALUNO,
        ]);
    }

    public function test_pertence_a_dados_pessoa_e_tipo_documento(): void
    {
        $this->seed(TipoDocumentoSeeder::class);
        $pessoa = $this->criarPessoa();
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();

        $documento = DocumentoPessoa::create([
            'dados_pessoa_id' => $pessoa->id,
            'tipo_documento_id' => $tipo->id,
            'nome_original' => 'bi-frente.pdf',
            'caminho' => 'documentos-pessoas/1/bi-frente.pdf',
            'mime_type' => 'application/pdf',
            'tamanho' => 1024,
        ]);

        $this->assertTrue($pessoa->documentos->contains($documento));
        $this->assertTrue($tipo->documentos->contains($documento));
        $this->assertSame($pessoa->id, $documento->dadosPessoa->id);
        $this->assertSame($tipo->id, $documento->tipoDocumento->id);
    }

    public function test_estado_descricao_e_sincronizada(): void
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

        $this->assertSame('Ativo', $documento->estado_descricao);
    }

    public function test_regista_autoria_do_utilizador_autenticado(): void
    {
        $this->seed(TipoDocumentoSeeder::class);
        $pessoa = $this->criarPessoa();
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();
        $user = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('segredo123')]);
        $this->actingAs($user);

        $documento = DocumentoPessoa::create([
            'dados_pessoa_id' => $pessoa->id,
            'tipo_documento_id' => $tipo->id,
            'nome_original' => 'bi.pdf',
            'caminho' => 'x',
            'mime_type' => 'application/pdf',
            'tamanho' => 10,
        ]);

        $this->assertSame($user->id, $documento->criado_por);
        $this->assertSame($user->id, $documento->editado_por);
    }

    public function test_soft_delete_preserva_o_registo_e_nao_altera_estado(): void
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

        $documento->delete();

        $this->assertSoftDeleted('documentos_pessoas', ['id' => $documento->id]);
        $comTrashed = DocumentoPessoa::withTrashed()->find($documento->id);
        $this->assertSame(1, $comTrashed->estado);
    }
}
