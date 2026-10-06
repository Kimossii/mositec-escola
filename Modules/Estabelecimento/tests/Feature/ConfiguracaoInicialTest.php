<?php

namespace Modules\Estabelecimento\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Modules\Estabelecimento\Http\Middleware\ExigirConfiguracaoInicial;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Modulo as ModuloEnum;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\UserPermissao;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class ConfiguracaoInicialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function desconfigurar(): void
    {
        Estabelecimento::current()->forceFill(['configurado_em' => null])->save();
    }

    private function utilizador(Perfil $perfil, string $email): User
    {
        $user = User::create(['name' => 'Utilizador', 'email' => $email, 'password' => Hash::make('x')]);
        $user->roles()->attach(Role::where('nome', $perfil->value)->first()->id);

        return $user;
    }

    public function test_quem_pode_editar_e_encaminhado_para_os_dados_da_escola(): void
    {
        $this->desconfigurar();
        $this->actingAs($this->utilizador(Perfil::ADMIN_ESCOLA, 'admin@example.com'));

        $this->get('/usuarios')->assertRedirect(route('estabelecimento.dados'));
    }

    public function test_pedidos_de_escrita_tambem_sao_encaminhados(): void
    {
        $this->desconfigurar();
        $this->actingAs($this->utilizador(Perfil::ADMIN_ESCOLA, 'admin@example.com'));

        $this->post('/usuarios/cadastrarUsuario', [])->assertRedirect(route('estabelecimento.dados'));
    }

    public function test_as_rotas_do_estabelecimento_e_o_logout_ficam_de_fora(): void
    {
        $this->desconfigurar();
        $this->actingAs($this->utilizador(Perfil::ADMIN_ESCOLA, 'admin@example.com'));

        $this->get('/estabelecimento')->assertOk();
        $this->get('/estabelecimento/aparencia')->assertOk();
        $this->post('/logout')->assertRedirect();
        $this->assertGuest();
    }

    public function test_depois_de_configurar_deixa_de_encaminhar(): void
    {
        $this->actingAs($this->utilizador(Perfil::ADMIN_ESCOLA, 'admin@example.com'));

        $this->get('/usuarios')->assertOk();
    }

    public function test_quem_nao_pode_editar_nao_e_encaminhado(): void
    {
        $this->desconfigurar();
        $this->actingAs($this->utilizador(Perfil::PROFESSOR, 'prof@example.com'));

        $resposta = $this->get('/');

        $this->assertNotSame(route('estabelecimento.dados'), $resposta->headers->get('Location'));

        // Resultado positivo: o professor chega ao conteúdo (não é encaminhado).
        $this->get('/usuarios')->assertStatus(403);
    }

    public function test_quem_so_pode_editar_sem_ver_nao_e_encaminhado_nem_entra_em_ciclo(): void
    {
        $this->desconfigurar();
        $user = User::create(['name' => 'So Editar', 'email' => 'editar@example.com', 'password' => Hash::make('x')]);
        UserPermissao::create([
            'users_id' => $user->id,
            'modulo_id' => Modulo::where('nome', ModuloEnum::ESTABELECIMENTO->value)->first()->id,
            'acao_id' => Acao::where('nome', 'editar')->first()->id,
            'permitido' => true,
        ]);
        $this->actingAs($user);

        $this->assertTrue($user->can('estabelecimento.editar'));
        $this->assertFalse($user->can('estabelecimento.ver'));

        $resposta = $this->get('/usuarios');

        $this->assertFalse($resposta->isRedirect(route('estabelecimento.dados')));
    }

    public function test_visitante_anonimo_nao_e_encaminhado(): void
    {
        $this->desconfigurar();

        $this->get('/login')->assertOk();
    }

    public function test_rota_inexistente_continua_a_dar_404(): void
    {
        $this->desconfigurar();
        $this->actingAs($this->utilizador(Perfil::ADMIN_ESCOLA, 'admin@example.com'));

        $this->get('/nao-existe-mesmo')->assertNotFound();
    }

    public function test_host_central_sem_tenant_passa_sem_consultar_o_estabelecimento(): void
    {
        config(['tenancy.hosts_centrais' => ['central.localhost']]);
        $this->desconfigurar();
        $this->actingAs($this->utilizador(Perfil::ADMIN_ESCOLA, 'admin@example.com'));

        // Rota descartável fora do grupo `web` (o HandleInertiaRequests lá resolve permissões
        // e falha sem tenant) e sem `can:`: só `auth` + o middleware sob teste. Um 500 só pode
        // vir deste middleware se consultar o estabelecimento sem tenant.
        Route::middleware(['auth', ExigirConfiguracaoInicial::class])->get('/_central-sem-tenant', fn () => response('ok'));

        $resposta = $this->get('http://central.localhost/_central-sem-tenant');

        $resposta->assertOk();
        $this->assertFalse($resposta->isRedirect(route('estabelecimento.dados')));
    }
}
