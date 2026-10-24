<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Financeiro\Actions\AlterarPrecoPlanoAction;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Models\AlteracaoPrecoPlano;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Tests\Concerns\ComAlteracaoPreco;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Modulo;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo as ModuloRegistro;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AlterarPrecoPlanoHttpTest extends TestCase
{
    use ComAlteracaoPreco;
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function planoComPropinas(): PlanoPropina
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

        foreach (range(1, 10) as $ordem) {
            $this->propina($matricula, $plano, $ordem);
        }

        return $plano;
    }

    /** Perfil personalizado só com as acções de UM módulo (padrão de PermissoesPorRecursoTest). */
    private function utilizadorSoCom(Modulo $modulo): User
    {
        $role = Role::create(['nome' => 100 + $modulo->value, 'descricao' => 'Só ' . $modulo->slug()]);
        $registo = ModuloRegistro::where('nome', $modulo->value)->firstOrFail();

        foreach (Acao::all() as $acao) {
            RolePermissao::create(['role_id' => $role->id, 'modulo_id' => $registo->id, 'acao_id' => $acao->id]);
        }

        $user = User::create(['name' => 'Teste', 'email' => 'so-' . $modulo->slug() . '@example.com', 'password' => Hash::make('x')]);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function urlPrevia(int $plano): string
    {
        return route('financeiro.configuracao.planos-propina.alterar-preco.pre-visualizar', $plano);
    }

    private function urlConfirmar(int $plano): string
    {
        return route('financeiro.configuracao.planos-propina.alterar-preco', $plano);
    }

    public function test_id_de_plano_nao_numerico_e_404_em_todas_as_rotas_de_plano(): void
    {
        $this->actingAs($this->adminEscola());
        $base = '/' . ltrim(parse_url(route('financeiro.configuracao.planos-propina.index'), PHP_URL_PATH), '/') . '/abc';

        $this->putJson($base, [])->assertNotFound();
        $this->patchJson($base . '/estado', [])->assertNotFound();
        $this->deleteJson($base)->assertNotFound();
        $this->postJson($base . '/alterar-preco/pre-visualizar', [])->assertNotFound();
        $this->postJson($base . '/alterar-preco', [])->assertNotFound();
    }

    public function test_previsualizar_devolve_o_contrato_json_e_nao_escreve(): void
    {
        $plano = $this->planoComPropinas();
        $this->actingAs($this->adminEscola());
        $antes = $this->fotografia();

        $resposta = $this->postJson($this->urlPrevia($plano->id), ['valor' => '30000', 'mes_efeito' => '2027-02', 'modo' => 1])->assertOk();

        $resposta->assertJsonPath('previsualizacao.modo', 1)
            ->assertJsonPath('previsualizacao.mes_efeito', '2027-02')
            ->assertJsonPath('previsualizacao.valor_novo', 3_000_000)
            ->assertJsonPath('previsualizacao.totais.afectadas', 5)
            ->assertJsonPath('previsualizacao.propinas_de_outros_anos', 0);
        $this->assertSame(64, strlen($resposta->json('previsualizacao.impressao_digital')));
        $this->assertSame($antes, $this->fotografia());
    }

    public function test_sem_modo_nao_ha_opcao_por_omissao(): void
    {
        $plano = $this->planoComPropinas();
        $this->actingAs($this->adminEscola());

        $this->postJson($this->urlPrevia($plano->id), ['valor' => '30000', 'mes_efeito' => '2027-02'])
            ->assertUnprocessable()->assertJsonValidationErrors(['modo' => 'Escolha como tratar as propinas já geradas.']);
        $this->postJson($this->urlPrevia($plano->id), ['valor' => '30000', 'mes_efeito' => '2027-02', 'modo' => 3])
            ->assertUnprocessable()->assertJsonValidationErrors(['modo' => 'Escolha como tratar as propinas já geradas.']);
        $this->postJson($this->urlPrevia($plano->id), ['valor' => '30000', 'mes_efeito' => '02/2027', 'modo' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors(['mes_efeito']);
        $this->postJson($this->urlPrevia($plano->id), ['valor' => '0', 'mes_efeito' => '2027-02', 'modo' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors(['valor']);
    }

    public function test_recusas_do_calculo_chegam_como_422_json(): void
    {
        $plano = $this->planoComPropinas();
        $this->actingAs($this->adminEscola());

        $this->postJson($this->urlPrevia($plano->id), ['valor' => '30000', 'mes_efeito' => '2027-08', 'modo' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors(['mes_efeito']);
        $this->postJson($this->urlPrevia($plano->id), ['valor' => '25000', 'mes_efeito' => '2027-02', 'modo' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors(['valor']);
    }

    public function test_confirmar_altera_regista_o_autor_e_redirecciona_com_mensagem(): void
    {
        $plano = $this->planoComPropinas();
        $admin = $this->adminEscola();
        $this->actingAs($admin);
        $impressao = $this->postJson($this->urlPrevia($plano->id), ['valor' => '30000', 'mes_efeito' => '2027-02', 'modo' => 1])->json('previsualizacao.impressao_digital');

        $this->from(route('financeiro.configuracao.planos-propina.index'))
            ->post($this->urlConfirmar($plano->id), [
                'valor' => '30000', 'mes_efeito' => '2027-02', 'modo' => 1,
                'motivo' => '  Actualização anual  ', 'impressao_digital' => $impressao,
            ])
            ->assertRedirect(route('financeiro.configuracao.planos-propina.index'))
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'Propina Mensal – Fase 2') && str_contains($m, '5 propina(s)'));

        $alteracao = AlteracaoPrecoPlano::query()->sole();
        $this->assertSame([$admin->id, 'Actualização anual', 5], [$alteracao->criado_por, $alteracao->motivo, $alteracao->total_propinas_afectadas]);
    }

    public function test_confirmar_exige_motivo_e_impressao_e_recusa_impressao_desactualizada(): void
    {
        $plano = $this->planoComPropinas();
        $this->actingAs($this->adminEscola());
        $base = ['valor' => '30000', 'mes_efeito' => '2027-02', 'modo' => 1];

        $this->post($this->urlConfirmar($plano->id), $base + ['impressao_digital' => str_repeat('a', 64)])->assertSessionHasErrors('motivo');
        $this->post($this->urlConfirmar($plano->id), $base + ['motivo' => 'abc', 'impressao_digital' => str_repeat('a', 64)])->assertSessionHasErrors('motivo');
        $this->post($this->urlConfirmar($plano->id), $base + ['motivo' => 'Motivo válido'])->assertSessionHasErrors('impressao_digital');
        $this->post($this->urlConfirmar($plano->id), $base + ['motivo' => 'Motivo válido', 'impressao_digital' => str_repeat('a', 64)])
            ->assertSessionHasErrors(['impressao_digital' => AlterarPrecoPlanoAction::MENSAGEM_DESACTUALIZADA]);
        $this->assertSame(0, AlteracaoPrecoPlano::query()->count());
    }

    public function test_exige_as_duas_permissoes(): void
    {
        $plano = $this->planoComPropinas();
        $corpo = ['valor' => '30000', 'mes_efeito' => '2027-02', 'modo' => 1, 'motivo' => 'Motivo', 'impressao_digital' => str_repeat('a', 64)];

        foreach ([$this->professor(), $this->utilizadorSoCom(Modulo::PROPINA), $this->utilizadorSoCom(Modulo::PLANO_PROPINA)] as $utilizador) {
            $this->actingAs($utilizador);
            $this->postJson($this->urlPrevia($plano->id), $corpo)->assertForbidden();
            $this->post($this->urlConfirmar($plano->id), $corpo)->assertForbidden();
        }

        $this->assertSame(0, AlteracaoPrecoPlano::query()->count());
    }

    public function test_plano_de_outro_tenant_da_404(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $planoDela = $this->noTenant($outro, fn () => $this->planoComPropinas()->id);
        $this->actingAs($this->adminEscola());

        $this->postJson($this->urlPrevia($planoDela), ['valor' => '30000', 'mes_efeito' => '2027-02', 'modo' => 1])->assertNotFound();
        $this->post($this->urlConfirmar($planoDela), ['valor' => '30000', 'mes_efeito' => '2027-02', 'modo' => 1, 'motivo' => 'Motivo', 'impressao_digital' => str_repeat('a', 64)])->assertNotFound();
    }

    public function test_a_lista_de_planos_traz_os_modos_e_os_inicios_de_periodo(): void
    {
        $plano = $this->planoComPropinas();
        $this->actingAs($this->adminEscola());

        $this->get(route('financeiro.configuracao.planos-propina.index', ['ano_lectivo_id' => $plano->ano_lectivo_id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('modosAlteracaoPreco', ModoAlteracaoPreco::opcoes())
                ->where('planos.data.0.tem_propinas', true)
                ->has('planos.data.0.inicios_periodo', 10)
                ->where('planos.data.0.inicios_periodo.0', ['valor' => '2026-09', 'rotulo' => 'Set/2026'])
                ->where('planos.data.0.inicios_periodo.5', ['valor' => '2027-02', 'rotulo' => 'Fev/2027']));
    }
}
