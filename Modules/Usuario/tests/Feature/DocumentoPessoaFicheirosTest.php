<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Actions\CriarDocumentoPessoaAction;
use Modules\Usuario\Database\Seeders\TipoDocumentoSeeder;
use Modules\Usuario\DTO\DocumentoPessoaDTO;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\DocumentoPessoa;
use Modules\Usuario\Models\TipoDocumento;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class DocumentoPessoaFicheirosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documentos');
        $this->seed(PermissaoDatabaseSeeder::class);
        $this->seed(TipoDocumentoSeeder::class);
    }

    private function criarDocumento(): DocumentoPessoa
    {
        $pessoa = DadosPessoa::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();
        $dto = new DocumentoPessoaDTO(tipo_documento_id: $tipo->id, numero_documento: '1', data_emissao: null, data_validade: null, observacoes: null);

        return app(CriarDocumentoPessoaAction::class)->executar($pessoa, $dto, UploadedFile::fake()->create('bi.pdf', 100, 'application/pdf'));
    }

    public function test_o_documento_fica_sob_o_prefixo_do_tenant_e_da_pessoa(): void
    {
        $documento = $this->criarDocumento();

        $this->assertStringStartsWith("tenants/{$this->tenant->id}/documentos-pessoas/{$documento->dados_pessoa_id}/", $documento->caminho);
        Storage::disk('documentos')->assertExists($documento->caminho);
    }

    public function test_o_documento_de_b_fica_sob_o_prefixo_de_b(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        $deB = $this->noTenant($outro, function () {
            $this->seed(TipoDocumentoSeeder::class);

            return $this->criarDocumento();
        });

        $this->assertStringStartsWith("tenants/{$outro->id}/documentos-pessoas/", $deB->caminho);
    }

    public function test_o_disco_documentos_e_privado(): void
    {
        $disco = config('filesystems.disks.documentos');

        $this->assertArrayNotHasKey('url', $disco);
        $this->assertStringStartsNotWith(config('filesystems.disks.public.root'), $disco['root']);
        $this->assertStringStartsNotWith(public_path(), $disco['root']);
    }

    public function test_download_e_visualizar_de_documento_de_b_a_partir_de_a_da_404(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $deB = $this->noTenant($outro, function () {
            $this->seed(TipoDocumentoSeeder::class);

            return $this->criarDocumento();
        });
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => Hash::make('segredo123')]);
        $admin->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->firstOrFail()->id);

        $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, "/documentos-pessoa/{$deB->id}/download"))->assertNotFound();
        $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, "/documentos-pessoa/{$deB->id}/visualizar"))->assertNotFound();
    }

    public function test_download_do_proprio_tenant_funciona(): void
    {
        $documento = $this->criarDocumento();
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => Hash::make('segredo123')]);
        $admin->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->firstOrFail()->id);

        $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, "/documentos-pessoa/{$documento->id}/download"))->assertOk();
    }

    public function test_download_e_visualizar_recusam_caminho_que_nao_e_do_tenant(): void
    {
        $documento = $this->criarDocumento();
        Storage::disk('documentos')->put('tenants/999/documentos-pessoas/1/alheio.pdf', '%PDF-1.4');
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => Hash::make('segredo123')]);
        $admin->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->firstOrFail()->id);

        foreach (['tenants/999/documentos-pessoas/1/alheio.pdf', "tenants/{$this->tenant->id}/../999/documentos-pessoas/1/alheio.pdf"] as $caminho) {
            $documento->forceFill(['caminho' => $caminho])->save();

            $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, "/documentos-pessoa/{$documento->id}/download"))->assertNotFound();
            $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, "/documentos-pessoa/{$documento->id}/visualizar"))->assertNotFound();
        }
    }
}
