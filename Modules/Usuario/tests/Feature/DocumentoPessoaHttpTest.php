<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Database\Seeders\TipoDocumentoSeeder;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\DocumentoPessoa;
use Modules\Usuario\Models\TipoDocumento;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class DocumentoPessoaHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
        $this->seed(TipoDocumentoSeeder::class);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => Hash::make('segredo123')]);
        $admin->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id);
        $this->actingAs($admin);

        return $admin;
    }

    private function criarPessoa(): DadosPessoa
    {
        return DadosPessoa::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
    }

    public function test_admin_faz_upload_de_documento(): void
    {
        Storage::fake('documentos');
        $this->actingAsAdmin();
        $pessoa = $this->criarPessoa();
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();

        $response = $this->post("/dados-pessoais/{$pessoa->id}/documentos", [
            'tipo_documento_id' => $tipo->id,
            'numero_documento' => '0012345LA042',
            'ficheiro' => UploadedFile::fake()->create('bi.pdf', 100, 'application/pdf'),
        ]);

        $response->assertRedirect();
        $this->assertSame(1, DocumentoPessoa::count());
    }

    public function test_utilizador_sem_permissao_nao_faz_upload(): void
    {
        Storage::fake('documentos');
        $semPermissao = User::create(['name' => 'Sem Permissao', 'email' => 'sem.permissao@example.com', 'password' => Hash::make('segredo123')]);
        $this->actingAs($semPermissao);
        $pessoa = $this->criarPessoa();
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();

        $response = $this->post("/dados-pessoais/{$pessoa->id}/documentos", [
            'tipo_documento_id' => $tipo->id,
            'ficheiro' => UploadedFile::fake()->create('bi.pdf', 100, 'application/pdf'),
        ]);

        $response->assertForbidden();
        $this->assertSame(0, DocumentoPessoa::count());
    }

    public function test_lista_documentos_da_pessoa(): void
    {
        $this->actingAsAdmin();
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

        $response = $this->get("/dados-pessoais/{$pessoa->id}/documentos");

        $response->assertOk();
        $response->assertJsonCount(1, 'documentos');
    }

    public function test_download_devolve_o_ficheiro_para_quem_tem_permissao(): void
    {
        Storage::fake('documentos');
        $this->actingAsAdmin();
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

        $response = $this->get("/documentos-pessoa/{$documento->id}/download");

        $response->assertOk();
    }

    public function test_download_e_recusado_sem_permissao(): void
    {
        Storage::fake('documentos');
        $semPermissao = User::create(['name' => 'Sem Permissao', 'email' => 'sem.permissao@example.com', 'password' => Hash::make('segredo123')]);
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
        $this->actingAs($semPermissao);

        $response = $this->get("/documentos-pessoa/{$documento->id}/download");

        $response->assertForbidden();
    }

    public function test_elimina_documento_por_soft_delete(): void
    {
        $this->actingAsAdmin();
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

        $response = $this->delete("/documentos-pessoa/{$documento->id}");

        $response->assertRedirect();
        $this->assertSoftDeleted('documentos_pessoas', ['id' => $documento->id]);
    }

    public function test_alterna_estado_do_documento(): void
    {
        $this->actingAsAdmin();
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

        $response = $this->patch("/documentos-pessoa/{$documento->id}/estado");

        $response->assertRedirect();
        $this->assertSame(0, $documento->fresh()->estado);
    }

    public function test_visualizar_devolve_o_ficheiro_inline_para_quem_tem_permissao(): void
    {
        Storage::fake('documentos');
        $this->actingAsAdmin();
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

        $response = $this->get("/documentos-pessoa/{$documento->id}/visualizar");

        $response->assertOk();
        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_visualizar_e_recusado_sem_permissao(): void
    {
        Storage::fake('documentos');
        $semPermissao = User::create(['name' => 'Sem Permissao', 'email' => 'sem.permissao3@example.com', 'password' => Hash::make('segredo123')]);
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
        $this->actingAs($semPermissao);

        $response = $this->get("/documentos-pessoa/{$documento->id}/visualizar");

        $response->assertForbidden();
    }

    public function test_lista_tipos_de_documento_disponiveis(): void
    {
        $this->actingAsAdmin();

        $response = $this->get('/tipos-documentos');

        $response->assertOk();
        $response->assertJsonCount(6, 'tipos');
    }

    public function test_utilizador_sem_permissao_nao_ve_tipos_de_documento(): void
    {
        $semPermissao = User::create(['name' => 'Sem Permissao', 'email' => 'sem.permissao2@example.com', 'password' => Hash::make('segredo123')]);
        $this->actingAs($semPermissao);

        $response = $this->get('/tipos-documentos');

        $response->assertForbidden();
    }
}
