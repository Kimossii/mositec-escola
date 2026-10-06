<?php

namespace Modules\Aluno\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Modules\Aluno\Actions\AtualizarAlunoAction;
use Modules\Aluno\Actions\CriarAlunoAction;
use Modules\Aluno\DTO\AlunoDTO;
use Modules\Aluno\Models\Aluno;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Tenant\Models\Tenant;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\User;
use Tests\TestCase;

/**
 * Fotos de alunos: disco privado, prefixo do tenant, servidas por rota autenticada.
 */
class AlunoFotoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $outro;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('privado');
        Storage::fake('public');
        $this->outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function utilizador(Perfil $perfil, string $email = 'u@example.com'): User
    {
        $user = User::create(['name' => 'U', 'email' => $email, 'password' => Hash::make('segredo123')]);
        $user->roles()->attach(Role::where('nome', $perfil->value)->firstOrFail()->id);

        return $user;
    }

    private function dto(string $bi = 'BI0001'): AlunoDTO
    {
        return new AlunoDTO(
            dadosPessoaId: null, nomeCompleto: 'Ana Silva', email: null, telefone: null,
            dataNascimento: '2010-05-01', sexo: 0, numeroIdentificacao: $bi,
        );
    }

    private function alunoComFoto(): Aluno
    {
        return app(CriarAlunoAction::class)->executar($this->dto(), UploadedFile::fake()->image('foto.jpg'));
    }

    public function test_a_foto_e_gravada_no_disco_privado_com_prefixo_do_tenant(): void
    {
        $aluno = $this->alunoComFoto();

        $this->assertStringStartsWith("tenants/{$this->tenant->id}/alunos/fotos/", $aluno->foto_path);
        Storage::disk('privado')->assertExists($aluno->foto_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_foto_de_b_fica_sob_o_prefixo_de_b(): void
    {
        $aluno = $this->noTenant($this->outro, fn () => $this->alunoComFoto());

        $this->assertStringStartsWith("tenants/{$this->outro->id}/alunos/fotos/", $aluno->foto_path);
        Storage::disk('privado')->assertExists($aluno->foto_path);
    }

    public function test_foto_url_aponta_para_a_rota_autenticada_e_nunca_para_storage(): void
    {
        $aluno = $this->alunoComFoto();

        $this->assertSame(route('alunos.foto', $aluno), $aluno->foto_url);
        $this->assertStringNotContainsString('/storage/', $aluno->foto_url);
        $this->assertNull(Aluno::make()->foto_url);
    }

    public function test_utilizador_autenticado_descarrega_a_foto(): void
    {
        $aluno = $this->alunoComFoto();

        $resposta = $this->actingAs($this->utilizador(Perfil::ADMIN_ESCOLA))->get($this->urlDoTenant($this->tenant, "/alunos/{$aluno->id}/foto"));

        $resposta->assertOk();
        $resposta->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringContainsString('private', $resposta->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('public', $resposta->headers->get('Cache-Control'));
        $this->assertSame(Storage::disk('privado')->get($aluno->foto_path), $resposta->streamedContent());
    }

    public function test_sem_sessao_nao_descarrega_a_foto(): void
    {
        $aluno = $this->alunoComFoto();

        $resposta = $this->get($this->urlDoTenant($this->tenant, "/alunos/{$aluno->id}/foto"));

        $resposta->assertRedirect();
        $this->assertNotSame(200, $resposta->getStatusCode());
    }

    public function test_aluno_de_b_no_dominio_de_a_da_404(): void
    {
        $deB = $this->noTenant($this->outro, fn () => $this->alunoComFoto());

        $this->actingAs($this->utilizador(Perfil::ADMIN_ESCOLA))
            ->get($this->urlDoTenant($this->tenant, "/alunos/{$deB->id}/foto"))
            ->assertNotFound();
    }

    public function test_utilizador_sem_permissao_aluno_ver_recebe_403(): void
    {
        $aluno = $this->alunoComFoto();

        $this->actingAs($this->utilizador(Perfil::PROFESSOR))
            ->get($this->urlDoTenant($this->tenant, "/alunos/{$aluno->id}/foto"))
            ->assertForbidden();
    }

    public function test_aluno_sem_foto_da_404(): void
    {
        $aluno = app(CriarAlunoAction::class)->executar($this->dto());

        $this->actingAs($this->utilizador(Perfil::ADMIN_ESCOLA))
            ->get($this->urlDoTenant($this->tenant, "/alunos/{$aluno->id}/foto"))
            ->assertNotFound();
    }

    public function test_foto_registada_mas_ficheiro_em_falta_da_404(): void
    {
        $aluno = $this->alunoComFoto();
        Storage::disk('privado')->delete($aluno->foto_path);

        $this->actingAs($this->utilizador(Perfil::ADMIN_ESCOLA))
            ->get($this->urlDoTenant($this->tenant, "/alunos/{$aluno->id}/foto"))
            ->assertNotFound();
    }

    public function test_substituir_a_foto_apaga_a_anterior_no_disco_privado(): void
    {
        $aluno = $this->alunoComFoto();
        $antiga = $aluno->foto_path;

        $actualizado = app(AtualizarAlunoAction::class)->executar($aluno, $this->dto(), UploadedFile::fake()->image('nova.jpg'));

        Storage::disk('privado')->assertMissing($antiga);
        Storage::disk('privado')->assertExists($actualizado->foto_path);
        $this->assertStringStartsWith("tenants/{$this->tenant->id}/alunos/fotos/", $actualizado->foto_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_o_disco_privado_nao_e_servido_pelo_symlink_publico(): void
    {
        $disco = config('filesystems.disks.privado');

        $this->assertSame('local', $disco['driver']);
        $this->assertArrayNotHasKey('url', $disco);
        $this->assertNotSame(config('filesystems.disks.public.root'), $disco['root']);
        $this->assertStringStartsNotWith(public_path(), $disco['root']);
        $this->assertStringStartsNotWith(config('filesystems.disks.public.root'), $disco['root']);
    }

    public function test_foto_path_de_outro_tenant_ou_com_ponto_ponto_da_404_na_rota(): void
    {
        $aluno = $this->alunoComFoto();
        Storage::disk('privado')->put('tenants/999/alunos/fotos/alheia.jpg', 'x');
        Storage::disk('privado')->put('tenants/999/x.jpg', 'x');
        $admin = $this->utilizador(Perfil::ADMIN_ESCOLA);

        foreach (['tenants/999/alunos/fotos/alheia.jpg', "tenants/{$this->tenant->id}/../999/x.jpg", 'sem-prefixo.jpg'] as $caminho) {
            $aluno->forceFill(['foto_path' => $caminho])->save();

            $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, "/alunos/{$aluno->id}/foto"))->assertNotFound();
        }
    }

    public function test_substituir_a_foto_nao_apaga_um_caminho_que_nao_e_do_tenant(): void
    {
        $aluno = $this->alunoComFoto();
        Storage::disk('privado')->put('tenants/999/alunos/fotos/alheia.jpg', 'x');
        $aluno->forceFill(['foto_path' => 'tenants/999/alunos/fotos/alheia.jpg'])->save();

        app(AtualizarAlunoAction::class)->executar($aluno, $this->dto(), UploadedFile::fake()->image('nova.jpg'));

        Storage::disk('privado')->assertExists('tenants/999/alunos/fotos/alheia.jpg');
    }
}
