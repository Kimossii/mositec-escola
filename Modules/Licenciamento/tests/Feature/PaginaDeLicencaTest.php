<?php

namespace Modules\Licenciamento\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Licenciamento\Actions\InstalarLicencaAction;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Licenciamento\Http\Requests\InstalarLicencaRequest;
use Modules\Licenciamento\Models\DireitosLocais;
use Modules\Licenciamento\Services\CodigoDaInstalacao;
use Modules\Licenciamento\Tests\Support\ComLicencaDeTeste;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Plataforma\Tests\Feature\Concerns\ComPainelDaPlataforma;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class PaginaDeLicencaTest extends TestCase
{
    use ComLicencaDeTeste;
    use ComPainelDaPlataforma;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararLicencas();
        $this->congelarRelogioDeTeste();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function utilizador(Perfil $perfil, string $email = 'u@example.com'): User
    {
        $user = User::create(['name' => 'Utilizador', 'email' => $email, 'password' => Hash::make('x')]);
        $user->roles()->attach(Role::where('nome', $perfil->value)->first()->id);

        return $user;
    }

    private function admin(): User
    {
        return $this->utilizador(Perfil::ADMIN_ESCOLA, 'admin@example.com');
    }

    private function instalar(array $extra = []): void
    {
        app(InstalarLicencaAction::class)->executar($this->tenant->id, $this->tenant->codigo, $this->tokenDeTeste($extra));
    }

    public function test_admin_ve_estado_e_codigo_da_instalacao(): void
    {
        $this->instalar();

        $this->actingAs($this->admin())->get(route('licenca.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Licenciamento/Licenca/Index')
                ->where('licenca_estado.modo', 'normal')
                ->where('licenca_estado.tem_licenca', true)
                ->where('licenca_estado.codigo_instalacao', app(CodigoDaInstalacao::class)->obter())
                ->where('licenca_estado.expira_em', '2027-01-01T00:00:00+00:00')
                ->where('licenca_estado.bloqueia_em', '2027-01-08T00:00:00+00:00')
                ->where('licenca_estado.tolerancia_dias', 7)
                ->where('licenca_estado.modalidade', 'Online')
                ->where('licenca_estado.modulos.0.nome', 'Financeiro')
                ->where('licenca_estado.modulos.1.nome', 'Avaliação'));
    }

    public function test_pagina_mostra_limites_e_utilizacao_com_limite_nulo_sem_limite(): void
    {
        $this->instalar();
        $this->admin();

        $this->actingAs(User::first())->get(route('licenca.index'))
            ->assertInertia(fn (Assert $p) => $p
                ->where('licenca_estado.limites.0.recurso', 'alunos')
                ->where('licenca_estado.limites.0.limite', 500)
                ->where('licenca_estado.limites.0.utilizacao', 0)
                ->where('licenca_estado.limites.1.recurso', 'utilizadores')
                ->where('licenca_estado.limites.1.limite', null)
                ->where('licenca_estado.limites.1.utilizacao', 0)); // o administrador (único utilizador do teste) não conta
    }

    public function test_admin_cola_licenca_valida_e_fica_activa(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('licenca.instalar'), ['token' => '  '.$this->tokenDeTeste()."\n"])
            ->assertRedirect(route('licenca.index'))
            ->assertSessionHas('success');

        $this->get(route('licenca.index'))->assertInertia(fn (Assert $p) => $p
            ->where('licenca_estado.modo', 'normal')->where('licenca_estado.tem_licenca', true));
        $this->get('/')->assertOk();
    }

    public function test_admin_carrega_ficheiro_valido(): void
    {
        $this->actingAs($this->admin());
        $ficheiro = UploadedFile::fake()->createWithContent('licenca.txt', $this->tokenDeTeste()."\n");

        $this->post(route('licenca.instalar'), ['ficheiro' => $ficheiro])
            ->assertRedirect(route('licenca.index'))->assertSessionHasNoErrors();

        $this->assertSame(1, DireitosLocais::query()->count());
    }

    public function test_licenca_invalida_mostra_erro_sem_detalhe_criptografico(): void
    {
        $this->actingAs($this->admin());

        $r = $this->from(route('licenca.index'))->post(route('licenca.instalar'), ['token' => 'v4.public.lixo']);

        $r->assertRedirect(route('licenca.index'))->assertSessionHasErrors('token');
        $mensagem = session('errors')->first('token');
        $this->assertSame('A licença não é válida: a assinatura ou o conteúdo não foram aceites.', $mensagem);
        foreach (['sodium', 'ed25519', 'paseto', 'v4.public'] as $pista) {
            $this->assertStringNotContainsStringIgnoringCase($pista, $mensagem);
        }
        $this->assertSame(0, DireitosLocais::query()->count());
    }

    public function test_licenca_de_outra_escola_e_recusada(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('licenca.instalar'), ['token' => $this->tokenDeTeste(['escola' => 'OUTRA-ESCOLA'])])
            ->assertSessionHasErrors(['token' => 'Esta licença pertence a outra escola.']);
        $this->post(route('licenca.instalar'), ['token' => $this->tokenDeTeste(['instalacao' => 'INST-OUTRA'])])
            ->assertSessionHasErrors(['token' => 'Esta licença pertence a outra instalação.']);
        $this->assertSame(0, DireitosLocais::query()->count());
    }

    public function test_ficheiro_vazio_ou_grande_demais_e_recusado(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('licenca.instalar'), ['ficheiro' => UploadedFile::fake()->createWithContent('v.txt', "  \n")])
            ->assertSessionHasErrors('token');
        $this->post(route('licenca.instalar'), ['ficheiro' => UploadedFile::fake()->create('grande.txt', 64, 'text/plain')])
            ->assertSessionHasErrors('ficheiro');
        $this->post(route('licenca.instalar'), [])->assertSessionHasErrors('token');

        // Texto grande demais: a validação falha mas o token não fica na sessão (`_old_input`).
        $grande = str_repeat('A', (InstalarLicencaRequest::MAX_KB * 1024) + 1);
        $this->post(route('licenca.instalar'), ['token' => $grande])->assertSessionHasErrors('token');
        $this->assertArrayNotHasKey('token', (array) session()->getOldInput());
        $this->assertStringNotContainsString('AAAAAAAAAA', json_encode(session()->all()));
        $this->assertSame(0, DireitosLocais::query()->count());
    }

    public function test_professor_recebe_resposta_generica(): void
    {
        $this->instalar();
        $professor = $this->utilizador(Perfil::PROFESSOR, 'p@example.com');

        $this->actingAs($professor)->get(route('licenca.index'))->assertNotFound();
        $this->post(route('licenca.instalar'), ['token' => $this->tokenDeTeste()])->assertNotFound();

        // Em modo bloqueado o gate responde antes, com a mensagem genérica.
        DireitosLocais::query()->update(['revogada_em' => now()]);
        app(\Modules\Licenciamento\Services\DireitosService::class)->esquecer();
        $this->get(route('licenca.index'))->assertStatus(503)->assertSee('Ocorreu um erro. Tente mais tarde.')->assertDontSee('icen');
    }

    public function test_instalacao_nova_sem_licenca_so_mostra_esta_pagina(): void
    {
        $this->actingAs($this->admin());

        $this->get('/')->assertRedirect(route('licenca.index'));
        $this->get(route('licenca.index'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('licenca_estado.modo', 'bloqueado')->where('licenca_estado.tem_licenca', false)
            ->where('licenca_estado.expira_em', null)->where('licenca_estado.limites.0.limite', null));
        $this->assertAuthenticated();
    }

    public function test_administrador_em_modo_bloqueado_consegue_instalar_a_renovacao(): void
    {
        $this->instalar();
        $this->congelarRelogioDeTeste('2027-02-01T00:00:00+00:00'); // depois da tolerância
        $this->actingAs($this->admin());
        $this->get('/')->assertRedirect(route('licenca.index'));

        $renovada = $this->tokenDeTeste(['jti' => 'lic-2', 'iat' => '2027-01-15T00:00:00+00:00', 'nbf' => '2027-01-15T00:00:00+00:00', 'exp' => '2028-01-01T00:00:00+00:00']);
        $this->post(route('licenca.instalar'), ['token' => $renovada])->assertRedirect(route('licenca.index'))->assertSessionHasNoErrors();

        $this->get('/')->assertOk();
        $this->get(route('licenca.index'))->assertInertia(fn (Assert $p) => $p->where('licenca_estado.modo', 'normal'));
    }

    public function test_a_pagina_nunca_expoe_o_token_guardado(): void
    {
        $token = $this->tokenDeTeste();
        $this->instalar();
        $this->actingAs($this->admin());

        $r = $this->get(route('licenca.index'));

        $this->assertStringNotContainsString($token, $r->getContent());
        $this->assertStringNotContainsString(substr($token, 12, 40), $r->getContent());
        $r->assertInertia(fn (Assert $p) => $p->missing('licenca_estado.token')->missing('licenca_estado.claims'));
    }

    public function test_prop_de_aviso_so_para_o_administrador(): void
    {
        $this->instalar();
        $this->congelarRelogioDeTeste('2027-01-03T00:00:00+00:00');

        $this->actingAs($this->admin())->get(route('licenca.index'))
            ->assertInertia(fn (Assert $p) => $p->where('licenca.modo', 'aviso')->has('licenca.dias_restantes'));
        $this->actingAs($this->utilizador(Perfil::PROFESSOR, 'p@example.com'))->get('/')
            ->assertInertia(fn (Assert $p) => $p->where('licenca', null));
    }

    /** Segue redirecções (no máximo 6) e devolve a resposta final e o caminho percorrido. */
    private function seguir(string $uri): array
    {
        $caminho = [];
        for ($i = 0; $i < 6; $i++) {
            $r = $this->get($uri);
            $caminho[] = parse_url($uri, PHP_URL_PATH);
            if (! $r->isRedirect()) {
                return [$r, $caminho];
            }
            $uri = $r->headers->get('Location');
        }
        $this->fail('Ciclo de redirecções: '.implode(' -> ', $caminho));
    }

    public function test_escola_por_configurar_e_bloqueada_chega_a_licenca_sem_ciclo(): void
    {
        Estabelecimento::current()->forceFill(['configurado_em' => null, 'tipo' => null])->save();
        $this->actingAs($this->admin());

        [$r, $caminho] = $this->seguir('/');
        $r->assertOk();
        $this->assertSame('/licenca', end($caminho));

        $this->post(route('licenca.instalar'), ['token' => $this->tokenDeTeste()])->assertRedirect(route('licenca.index'));

        // Instalada a licença, a configuração inicial retoma.
        $this->get('/')->assertRedirect(route('estabelecimento.dados'));
    }

    public function test_administrador_com_senha_temporaria_e_bloqueado_troca_a_senha_primeiro_e_depois_chega_a_licenca(): void
    {
        $admin = $this->admin();
        $admin->forceFill(['deve_alterar_senha' => true])->save();
        $this->actingAs($admin);

        [$r, $caminho] = $this->seguir(route('licenca.index'));
        $r->assertOk();
        $this->assertSame('/alterar-senha', end($caminho));

        $admin->forceFill(['deve_alterar_senha' => false])->save();
        [$r, $caminho] = $this->seguir('/');
        $r->assertOk();
        $this->assertSame('/licenca', end($caminho));

        $this->post(route('licenca.instalar'), ['token' => $this->tokenDeTeste()])->assertRedirect(route('licenca.index'));
        $this->get('/')->assertOk();
    }
}
