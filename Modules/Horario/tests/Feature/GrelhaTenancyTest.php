<?php

namespace Modules\Horario\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Tenancy\Exceptions\AlteracaoDeTenantProibida;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\TenantContext;
use Modules\Horario\Models\GrelhaEntrada;
use Modules\Horario\Tests\Support\CenarioDeHorario;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Tenant\Models\Tenant;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class GrelhaTenancyTest extends TestCase
{
    use CenarioDeHorario;
    use RefreshDatabase;

    private Tenant $outro;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-02 10:00:00');
        $this->outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function administrador(): User
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $user = User::create(['name' => 'Admin', 'email' => 'admin-a@example.com', 'password' => Hash::make('segredo123')]);
        $user->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->firstOrFail()->id);

        return $user;
    }

    public function test_a_grelha_do_dominio_so_mostra_entradas_do_proprio_tenant(): void
    {
        $admin = $this->administrador();
        $cA = $this->cenarioDeHorario();
        $this->criarEntrada($cA);
        $cB = $this->noTenant($this->outro, fn () => $this->cenarioDeHorario());
        $this->noTenant($this->outro, fn () => $this->criarEntrada($cB));

        $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, '/grelha-horaria?turma_id=' . $cA['turma']->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('grelha.entradas', 1));

        // A turma de B pedida no domínio de A não existe para A.
        $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, '/grelha-horaria?turma_id=' . $cB['turma']->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('grelha.entradas', 0)->has('grelha.tempos', 0));
    }

    public function test_pedir_no_dominio_de_a_uma_entrada_de_b_da_404(): void
    {
        $admin = $this->administrador();
        $idDeB = $this->noTenant($this->outro, fn () => $this->criarEntrada($this->cenarioDeHorario(), ['vigencia_inicio' => '2026-04-01']))->id;

        $this->actingAs($admin)->delete($this->urlDoTenant($this->tenant, "/grelha-horaria/{$idDeB}"))->assertNotFound();
        $this->assertNotNull($this->noTenant($this->outro, fn () => GrelhaEntrada::find($idDeB)));
    }

    public function test_alterar_o_tenant_de_uma_entrada_lanca_excepcao(): void
    {
        $entrada = $this->criarEntrada($this->cenarioDeHorario())->fresh();
        $entrada->tenant_id = $this->outro->id;

        $this->expectException(AlteracaoDeTenantProibida::class);

        $entrada->save();
    }

    public function test_sem_contexto_a_leitura_e_a_escrita_lancam(): void
    {
        $this->criarEntrada($this->cenarioDeHorario());
        app(TenantContext::class)->limpar();

        $this->expectException(TenantNaoResolvido::class);

        GrelhaEntrada::query()->get();
    }

    public function test_a_base_recusa_referencias_a_dados_de_outro_tenant(): void
    {
        $cA = $this->cenarioDeHorario();
        $cB = $this->noTenant($this->outro, fn () => $this->cenarioDeHorario());

        foreach ([
            'horario_id' => $cB['tempo1']->id,
            'disciplina_id' => $cB['disciplina']->id,
            'sala_id' => $cB['sala']->id,
        ] as $campo => $idDeB) {
            try {
                $this->criarEntrada($cA, [$campo => $idDeB]);
                $this->fail("A base aceitou {$campo} de outro tenant.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
