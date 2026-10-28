<?php

namespace Modules\Autenticacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class FotoDoUtilizadorTest extends TestCase
{
    use RefreshDatabase;

    private function utilizador(string $email = 'ana@example.com'): User
    {
        return User::create(['name' => 'Ana Silva', 'email' => $email, 'password' => Hash::make('x')]);
    }

    public function test_trocar_a_foto_elimina_a_anterior_e_nao_acumula_ficheiros(): void
    {
        Storage::fake('privado');
        $ana = $this->utilizador();

        $this->actingAs($ana)->post('/definicoes/foto', ['foto' => $this->imagemFalsa('a.png')])->assertRedirect();
        $primeira = $ana->fresh()->foto_path;
        Storage::disk('privado')->assertExists($primeira);

        $this->actingAs($ana)->post('/definicoes/foto', ['foto' => $this->imagemFalsa('b.jpg')])->assertRedirect();
        $segunda = $ana->fresh()->foto_path;

        $this->assertNotSame($primeira, $segunda);
        Storage::disk('privado')->assertMissing($primeira);
        Storage::disk('privado')->assertExists($segunda);
        $this->assertCount(1, Storage::disk('privado')->allFiles());
    }

    public function test_remover_a_foto_apaga_o_ficheiro_e_limpa_o_caminho(): void
    {
        Storage::fake('privado');
        $ana = $this->utilizador();
        $this->actingAs($ana)->post('/definicoes/foto', ['foto' => $this->imagemFalsa('a.png')]);
        $caminho = $ana->fresh()->foto_path;

        $this->actingAs($ana)->delete('/definicoes/foto')->assertRedirect();

        $this->assertNull($ana->fresh()->foto_path);
        Storage::disk('privado')->assertMissing($caminho);
    }

    public function test_ficheiros_invalidos_sao_recusados_e_a_foto_actual_mantem_se(): void
    {
        Storage::fake('privado');
        $ana = $this->utilizador();
        $this->actingAs($ana)->post('/definicoes/foto', ['foto' => $this->imagemFalsa('a.png')]);
        $actual = $ana->fresh()->foto_path;

        $this->actingAs($ana)->post('/definicoes/foto', ['foto' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertSessionHasErrors('foto');
        $this->actingAs($ana)->post('/definicoes/foto', [])->assertSessionHasErrors('foto');

        $this->assertSame($actual, $ana->fresh()->foto_path);
        $this->assertCount(1, Storage::disk('privado')->allFiles());
    }

    public function test_so_o_proprio_utilizador_ve_e_altera_a_sua_foto(): void
    {
        Storage::fake('privado');
        $ana = $this->utilizador();
        $outro = $this->utilizador('outro@example.com');
        $this->actingAs($ana)->post('/definicoes/foto', ['foto' => $this->imagemFalsa('a.png')]);

        $this->actingAs($ana)->get('/definicoes/foto')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($outro)->get('/definicoes/foto')->assertNotFound();
        $this->assertNull($outro->fresh()->foto_path);

        auth()->logout();
        $this->get('/definicoes/foto')->assertRedirect('/login');
    }

    public function test_o_endereco_versionado_da_foto_vai_logo_em_auth_foto_e_muda_a_cada_troca(): void
    {
        Storage::fake('privado');
        $ana = $this->utilizador();

        $this->actingAs($ana)->get('/definicoes')->assertInertia(fn (Assert $page) => $page->where('auth.foto', null));

        $this->actingAs($ana)->post('/definicoes/foto', ['foto' => $this->imagemFalsa('a.png')]);
        $primeira = $this->actingAs($ana)->get('/definicoes')->viewData('page')['props']['auth']['foto'];
        $this->assertStringStartsWith('/definicoes/foto?v=', $primeira);

        $this->actingAs($ana)->post('/definicoes/foto', ['foto' => $this->imagemFalsa('b.jpg')]);
        $segunda = $this->actingAs($ana)->get('/definicoes')->viewData('page')['props']['auth']['foto'];
        $this->assertNotSame($primeira, $segunda);
    }
}
