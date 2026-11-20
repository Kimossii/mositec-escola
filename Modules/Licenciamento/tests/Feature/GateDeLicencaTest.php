<?php

namespace Modules\Licenciamento\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Modules\Licenciamento\Actions\InstalarLicencaAction;
use Modules\Licenciamento\Models\DireitosLocais;
use Modules\Licenciamento\Tests\Support\ComLicencaDeTeste;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Plataforma\Tests\Feature\Concerns\ComPainelDaPlataforma;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class GateDeLicencaTest extends TestCase
{
    use ComLicencaDeTeste;
    use ComPainelDaPlataforma;
    use RefreshDatabase;

    private const GENERICA = 'Ocorreu um erro. Tente mais tarde.';

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

    private function instalarLicencaValida(array $extra = []): void
    {
        app(InstalarLicencaAction::class)->executar($this->tenant->id, $this->tenant->codigo, $this->tokenDeTeste($extra));
    }

    /** Sem licença instalada e com a flag ligada, a escola está BLOQUEADA. */
    private function bloquear(): void
    {
        config(['licenciamento.activo' => true]);
    }

    public function test_modo_normal_nao_interfere(): void
    {
        $this->instalarLicencaValida();

        foreach ([Perfil::ADMIN_ESCOLA, Perfil::ALUNO] as $i => $perfil) {
            $this->actingAs($this->utilizador($perfil, "n{$i}@example.com"))->get('/')->assertOk();
        }
    }

    public function test_aviso_so_aparece_ao_administrador(): void
    {
        $this->instalarLicencaValida();
        $this->congelarRelogioDeTeste('2026-12-15T00:00:00+00:00');

        $this->actingAs($this->utilizador(Perfil::ADMIN_ESCOLA, 'a@example.com'))->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('licenca.modo', 'aviso')
                ->where('licenca.bloqueia_em', '2027-01-08T00:00:00+00:00')
                ->where('licenca.dias_restantes', 24));

        foreach ([Perfil::PROFESSOR, Perfil::ALUNO] as $i => $perfil) {
            $this->actingAs($this->utilizador($perfil, "p{$i}@example.com"))->get('/')
                ->assertOk()
                ->assertInertia(fn (Assert $p) => $p->where('licenca', null));
        }
    }

    public function test_bloqueado_aluno_recebe_resposta_generica_e_perde_a_sessao(): void
    {
        $this->bloquear();

        $r = $this->actingAs($this->utilizador(Perfil::ALUNO))->get('/');

        $r->assertStatus(503)->assertSee(self::GENERICA)->assertDontSee('icen');
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->assertGuest();
    }

    public function test_bloqueado_professor_e_funcionario_idem(): void
    {
        $this->bloquear();

        foreach ([Perfil::PROFESSOR, Perfil::FUNCIONARIO, Perfil::ENCARREGADO] as $i => $perfil) {
            $this->actingAs($this->utilizador($perfil, "x{$i}@example.com"))->get('/')
                ->assertStatus(503)->assertSee(self::GENERICA);
            $this->assertGuest();
        }
    }

    public function test_bloqueado_api_devolve_503_sem_pistas(): void
    {
        $this->bloquear();
        Sanctum::actingAs($this->utilizador(Perfil::PROFESSOR));

        $r = $this->getJson('/api/v1/turmas');

        $r->assertStatus(503)->assertExactJson(['message' => self::GENERICA]);
        $cabecalhos = strtolower(json_encode($r->headers->all()));
        foreach (['licen', 'expir', 'motivo'] as $pista) {
            $this->assertStringNotContainsString($pista, strtolower($r->getContent()));
            $this->assertStringNotContainsString($pista, $cabecalhos);
        }
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
    }

    public function test_bloqueado_inertia_devolve_toast_generico(): void
    {
        $this->bloquear();

        $r = $this->actingAs($this->utilizador(Perfil::ALUNO))
            ->withHeaders(['X-Inertia' => 'true', 'Referer' => url('/outra')])
            ->delete('/alunos/1/matriculas/1');

        // Um só salto, sem 302 (que o browser repetiria como DELETE): 409 + X-Inertia-Location para o login.
        $r->assertStatus(409)->assertHeader('X-Inertia-Location', route('login'));
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->assertGuest();

        // O browser segue o Location com uma navegação completa (GET de documento), na mesma sessão, e a página renderiza o erro genérico.
        $this->flushHeaders();
        Auth::forgetGuards(); // em produção cada pedido é um processo novo; aqui o guard `sanctum` guardaria o utilizador anterior
        $final = $this->get($r->headers->get('X-Inertia-Location'));
        $final->assertOk()->assertInertia(function (Assert $p) {
            $p->where('errors.autorizacao', self::GENERICA);
            // Nas props da página, a prop `licenca` é null (só o nome da chave partilhada) e nada mais fala em licença.
            $props = $p->toArray()['props'];
            $this->assertNull($props['licenca']);
            unset($props['licenca']);
            $this->assertStringNotContainsStringIgnoringCase('licen', json_encode($props));
        });
    }

    public function test_bloqueado_inertia_put_do_admin_e_redireccionado_com_303(): void
    {
        $this->bloquear();

        $this->actingAs($this->utilizador(Perfil::ADMIN_ESCOLA))->withHeaders(['X-Inertia' => 'true'])
            ->put('/alunos/1/matriculas/1')->assertStatus(303)->assertRedirect(route('licenca.index'));
        $this->delete('/alunos/1/matriculas/1')->assertStatus(303);
    }

    public function test_bloqueado_admin_e_levado_a_pagina_de_licenca(): void
    {
        $this->bloquear();

        $this->actingAs($this->utilizador(Perfil::ADMIN_ESCOLA))->get('/')->assertRedirect(route('licenca.index'));
        $this->assertAuthenticated();

        // E a própria página da licença (nome licenca.*) está permitida.
        $this->get('/licenca')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Licenciamento/Licenca/Index'));
    }

    public function test_bloqueado_admin_acede_a_exportacao_e_logout(): void
    {
        $this->bloquear();
        $admin = $this->utilizador(Perfil::ADMIN_ESCOLA);

        // A rota permitida chega ao controller (que pode responder o que quiser, menos o 503 do gate).
        $this->actingAs($admin)->get(route('ficheiros.index'))->assertOk();
        $r = $this->get(route('usuario.exportar'));
        $this->assertNotSame(503, $r->getStatusCode());

        $this->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }

    public function test_bloqueado_admin_nao_acede_a_pautas_nem_matriculas(): void
    {
        $this->bloquear();
        $this->actingAs($this->utilizador(Perfil::ADMIN_ESCOLA));

        $this->get(route('avaliacao.pautas.index'))->assertRedirect(route('licenca.index'));
        $this->post('/alunos/1/matriculas')->assertRedirect(route('licenca.index'));
    }

    public function test_visitante_nao_autenticado_ve_o_login_normalmente(): void
    {
        $this->bloquear();

        $this->get('/login')->assertOk();
        $this->post('/login', ['email' => 'x@example.com', 'password' => 'errada'])->assertSessionHasErrors();
    }

    public function test_sessao_aberta_e_expulsa_no_pedido_seguinte(): void
    {
        $this->instalarLicencaValida();
        $aluno = $this->utilizador(Perfil::ALUNO);
        $this->actingAs($aluno)->get('/')->assertOk();

        // A licença é revogada com a sessão já aberta.
        DireitosLocais::query()->update(['revogada_em' => now()]);
        app(\Modules\Licenciamento\Services\DireitosService::class)->esquecer();

        $this->get('/')->assertStatus(503);
        $this->assertGuest();
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_flag_inactiva_nada_muda(): void
    {
        config(['licenciamento.activo' => false]);

        $this->actingAs($this->utilizador(Perfil::ALUNO))->get('/')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('licenca', null));
        $this->assertAuthenticated();
    }

    public function test_comando_agendado_corre_em_modo_bloqueado(): void
    {
        $this->bloquear();

        $this->assertSame(0, Artisan::call('exportacoes:limpar', ['--todos' => true]));
    }

    public function test_painel_da_plataforma_nao_e_afectado(): void
    {
        $this->bloquear();
        config(['tenancy.hosts_centrais' => [self::CENTRAL]]);

        $this->pedido('GET', $this->urlCentral('/plataforma/login'))->assertOk();
        $sessao = $this->entrarNoPainel($this->superAdmin());
        $this->noPainel('GET', '/plataforma', $sessao)->assertOk();
    }

    public function test_licenca_revogada_bloqueia(): void
    {
        $this->instalarLicencaValida();
        DireitosLocais::query()->update(['revogada_em' => now()]);

        $this->actingAs($this->utilizador(Perfil::ALUNO))->get('/')->assertStatus(503);
    }
}
