<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegraCobrancaTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/financeiro/configuracao/regras-cobranca';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function utilizadorCom(Perfil $perfil): User
    {
        $user = User::create(['name' => 'Teste', 'email' => $perfil->value . '@example.com', 'password' => Hash::make('x')]);
        $user->roles()->syncWithoutDetaching([Role::where('nome', $perfil->value)->first()->id]);

        return $user;
    }

    private function payload(array $sobrepor = []): array
    {
        return array_merge([
            'dia_vencimento' => 10,
            'dias_tolerancia' => 5,
            'permite_pagamento_parcial' => true,
            'permite_pagamento_antecipado' => false,
            'gerar_automaticamente' => false,
            'permite_negociacao' => false,
            'desconto_maximo_negociacao' => 0,
        ], $sobrepor);
    }

    public function test_admin_ve_a_tela_com_os_defaults(): void
    {
        $this->actingAs($this->utilizadorCom(Perfil::ADMIN_ESCOLA))
            ->get(self::URL)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Financeiro/RegrasCobranca/Edit')
                ->where('regra.dia_vencimento', 10)
                ->where('regra.dias_tolerancia', 5)
                ->missing('regra.tenant_id'));
    }

    public function test_tela_abre_num_tenant_sem_regra_e_cria_os_defaults(): void
    {
        DB::table('regras_cobranca')->delete();

        $this->actingAs($this->utilizadorCom(Perfil::ADMIN_ESCOLA))->get(self::URL)->assertOk();

        $this->assertSame(1, DB::table('regras_cobranca')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_professor_nao_ve_nem_edita(): void
    {
        $professor = $this->utilizadorCom(Perfil::PROFESSOR);

        $this->actingAs($professor)->get(self::URL)->assertForbidden();
        $this->actingAs($professor)->put(self::URL, $this->payload())->assertForbidden();
    }

    public function test_visitante_e_redireccionado_para_o_login(): void
    {
        $this->get(self::URL)->assertRedirect(route('login'));
    }

    public function test_admin_actualiza_as_regras(): void
    {
        $this->actingAs($this->utilizadorCom(Perfil::ADMIN_ESCOLA))
            ->put(self::URL, $this->payload([
                'dia_vencimento' => 15,
                'dias_tolerancia' => 7,
                'permite_pagamento_parcial' => true,
                'permite_pagamento_antecipado' => true,
                'gerar_automaticamente' => true,
                'permite_negociacao' => true,
                'desconto_maximo_negociacao' => 30,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $regra = RegraCobranca::doTenant();
        $this->assertSame(15, $regra->dia_vencimento);
        $this->assertSame(7, $regra->dias_tolerancia);
        $this->assertTrue($regra->permite_pagamento_parcial);
        $this->assertTrue($regra->permite_pagamento_antecipado);
        $this->assertTrue($regra->gerar_automaticamente);
        $this->assertTrue($regra->permite_negociacao);
        $this->assertSame(30, $regra->desconto_maximo_negociacao);
    }

    public function test_pode_desligar_todas_as_opcoes(): void
    {
        $admin = $this->utilizadorCom(Perfil::ADMIN_ESCOLA);
        RegraCobranca::doTenant()->update(['permite_pagamento_parcial' => true]);

        $this->actingAs($admin)
            ->put(self::URL, $this->payload(['permite_pagamento_parcial' => false]))
            ->assertSessionHasNoErrors();

        $this->assertFalse(RegraCobranca::doTenant()->permite_pagamento_parcial);
    }

    public function test_negociacao_desligada_grava_desconto_zero(): void
    {
        $this->actingAs($this->utilizadorCom(Perfil::ADMIN_ESCOLA))
            ->put(self::URL, $this->payload(['permite_negociacao' => false, 'desconto_maximo_negociacao' => 50]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, RegraCobranca::doTenant()->desconto_maximo_negociacao);
    }

    #[DataProvider('valoresInvalidos')]
    public function test_valores_invalidos_sao_rejeitados(string $campo, mixed $valor): void
    {
        $this->actingAs($this->utilizadorCom(Perfil::ADMIN_ESCOLA))
            ->from(self::URL)
            ->put(self::URL, $this->payload([$campo => $valor]))
            ->assertSessionHasErrors($campo);

        $this->assertSame(10, RegraCobranca::doTenant()->dia_vencimento);
    }

    public static function valoresInvalidos(): array
    {
        return [
            'dia 0' => ['dia_vencimento', 0],
            'dia 29' => ['dia_vencimento', 29],
            'tolerância negativa' => ['dias_tolerancia', -1],
            'tolerância 91' => ['dias_tolerancia', 91],
            'desconto 101' => ['desconto_maximo_negociacao', 101],
            'desconto negativo' => ['desconto_maximo_negociacao', -1],
            'dia não numérico' => ['dia_vencimento', 'abc'],
            'booleano inválido' => ['gerar_automaticamente', 'talvez'],
        ];
    }

    public function test_campos_em_falta_sao_rejeitados(): void
    {
        $this->actingAs($this->utilizadorCom(Perfil::ADMIN_ESCOLA))
            ->from(self::URL)
            ->put(self::URL, [])
            ->assertSessionHasErrors(['dia_vencimento', 'dias_tolerancia', 'permite_pagamento_parcial']);
    }

    public function test_tenant_id_forjado_e_ignorado_e_o_outro_tenant_nao_e_tocado(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $regraDoOutro = $this->noTenant($outro, fn () => RegraCobranca::doTenant());

        $this->actingAs($this->utilizadorCom(Perfil::ADMIN_ESCOLA))
            ->put(self::URL, $this->payload(['dia_vencimento' => 25, 'tenant_id' => $outro->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame(25, RegraCobranca::doTenant()->dia_vencimento);
        $this->assertSame($this->tenant->id, RegraCobranca::doTenant()->tenant_id);
        $this->assertSame(10, DB::table('regras_cobranca')->where('id', $regraDoOutro->id)->value('dia_vencimento'));
        $this->assertSame(2, DB::table('regras_cobranca')->count());
    }
}
