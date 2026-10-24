<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Modules\Financeiro\Enums\TipoMulta;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;
use Modules\Financeiro\Models\EscalaoMulta;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Support\FontesDePrecos;
use Modules\Financeiro\Support\PrecosDasMultas;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class EscaloesMultaTest extends TestCase
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

    private ?User $admin = null;

    private function admin(): User
    {
        return $this->admin ??= $this->utilizadorCom(Perfil::ADMIN_ESCOLA);
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
            'multa_activa' => true,
            'escaloes' => [['dias_atraso' => 1, 'tipo' => 0, 'valor' => '5']],
        ], $sobrepor);
    }

    private function esc(int $dias, int $tipo, string|int $valor): array
    {
        return ['dias_atraso' => $dias, 'tipo' => $tipo, 'valor' => $valor];
    }

    private function guardar(array $sobrepor = [])
    {
        return $this->actingAs($this->admin())->from(self::URL)->put(self::URL, $this->payload($sobrepor));
    }

    public function test_guarda_regra_e_escaloes_com_ordem_pela_posicao(): void
    {
        $this->guardar([
            'escaloes' => [$this->esc(1, 0, '5'), $this->esc(15, 0, '2,5'), $this->esc(30, 1, '1500,50')],
        ])->assertSessionHasNoErrors();

        $regra = RegraCobranca::doTenant();
        $this->assertTrue($regra->multa_activa);
        $escaloes = $regra->escaloes;
        $this->assertSame([1, 2, 3], $escaloes->pluck('ordem')->all());
        $this->assertSame([1, 15, 30], $escaloes->pluck('dias_atraso')->all());
        $this->assertSame([500, 250, 150050], $escaloes->pluck('valor')->all());
        $this->assertSame(TipoMulta::VALOR_FIXO, $escaloes[2]->tipo);
        $this->assertSame('Valor fixo', $escaloes[2]->tipo_descricao);
        $this->assertSame('Percentagem', $escaloes[0]->tipo_descricao);
    }

    public function test_ordem_enviada_pelo_cliente_e_ignorada(): void
    {
        $this->guardar([
            'escaloes' => [
                ['ordem' => 3, 'dias_atraso' => 1, 'tipo' => 0, 'valor' => '5'],
                ['ordem' => 1, 'dias_atraso' => 9, 'tipo' => 0, 'valor' => '6'],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame([1, 2], RegraCobranca::doTenant()->escaloes->pluck('ordem')->all());
        $this->assertSame([1, 9], RegraCobranca::doTenant()->escaloes->pluck('dias_atraso')->all());
    }

    public function test_quarto_escalao_e_rejeitado(): void
    {
        $this->guardar([
            'escaloes' => [$this->esc(1, 0, '1'), $this->esc(2, 0, '2'), $this->esc(3, 0, '3'), $this->esc(4, 0, '4')],
        ])->assertSessionHasErrors('escaloes');

        $this->assertSame(0, EscalaoMulta::count());
    }

    #[DataProvider('diasNaoCrescentes')]
    public function test_dias_tem_de_ser_estritamente_crescentes(array $dias): void
    {
        $escaloes = array_map(fn ($d) => $this->esc($d, 0, '5'), $dias);

        $this->guardar(['escaloes' => $escaloes])->assertSessionHasErrors();

        $this->assertSame(0, EscalaoMulta::count());
    }

    public static function diasNaoCrescentes(): array
    {
        return ['iguais' => [[5, 5]], 'decrescentes' => [[10, 3]], 'meio' => [[1, 10, 10]], 'depois desce' => [[1, 20, 5]]];
    }

    public function test_dias_invalidos_sao_rejeitados(): void
    {
        foreach ([0, -1, 'abc', 1.5, 3651, 99999999999999999999] as $dias) {
            $this->guardar(['escaloes' => [$this->esc(1, 0, '5'), ['dias_atraso' => $dias, 'tipo' => 0, 'valor' => '5']]])
                ->assertSessionHasErrors('escaloes.1.dias_atraso');
        }
    }

    public function test_tipo_invalido_e_rejeitado(): void
    {
        $this->guardar(['escaloes' => [$this->esc(1, 7, '5')]])->assertSessionHasErrors('escaloes.0.tipo');
        $this->guardar(['escaloes' => [['dias_atraso' => 1, 'valor' => '5']]])->assertSessionHasErrors('escaloes.0.tipo');
    }

    #[DataProvider('percentagensInvalidas')]
    public function test_percentagem_invalida_e_rejeitada(string|int|float $valor): void
    {
        $this->guardar(['escaloes' => [$this->esc(1, 0, $valor)]])->assertSessionHasErrors('escaloes.0.valor');
    }

    public static function percentagensInvalidas(): array
    {
        return ['zero' => ['0'], 'acima de 100' => ['100,01'], '101' => ['101'], 'negativa' => ['-1'], 'três casas' => ['2,555'],
            'texto' => ['abc'], 'vazia' => [''], 'enorme' => ['99999999999999999999'], 'milhares' => ['1.000']];
    }

    public function test_percentagem_converte_para_pontos_base(): void
    {
        foreach ([['2,5', 250], ['2.5', 250], ['100', 10000], ['0,01', 1], ['5', 500], [7, 700]] as [$entrada, $pontos]) {
            $this->guardar(['escaloes' => [$this->esc(1, 0, $entrada)]])->assertSessionHasNoErrors();
            $this->assertSame($pontos, RegraCobranca::doTenant()->escaloes->first()->valor, "entrada {$entrada}");
        }
    }

    public function test_valor_fixo_em_aoa_converte_para_unidades_menores(): void
    {
        $this->guardar(['escaloes' => [$this->esc(1, 1, '1500,50')]])->assertSessionHasNoErrors();

        $this->assertSame(150050, RegraCobranca::doTenant()->escaloes->first()->valor);
    }

    public function test_valor_fixo_em_moeda_sem_decimais(): void
    {
        ConfiguracaoMonetaria::doTenant()->update(['moeda' => 'JPY']);

        $this->guardar(['escaloes' => [$this->esc(1, 1, '1500')]])->assertSessionHasNoErrors();
        $this->assertSame(1500, RegraCobranca::doTenant()->escaloes->first()->valor);

        $this->guardar(['escaloes' => [$this->esc(1, 1, '1500,5')]])->assertSessionHasErrors('escaloes.0.valor');
    }

    public function test_valor_fixo_invalido_ou_zero_e_rejeitado(): void
    {
        foreach (['0', '0,00', 'abc', '-5', '1,234', '9999999999999999999999'] as $valor) {
            $this->guardar(['escaloes' => [$this->esc(1, 1, $valor)]])->assertSessionHasErrors('escaloes.0.valor');
        }
        $this->assertSame(0, EscalaoMulta::count());
    }

    public function test_multa_activa_exige_pelo_menos_um_escalao(): void
    {
        $this->guardar(['escaloes' => []])->assertSessionHasErrors('escaloes');
        $this->guardar(['escaloes' => null])->assertSessionHasErrors('escaloes');
    }

    private function semCamposDeMulta(array $sobrepor = []): array
    {
        return array_diff_key($this->payload($sobrepor), ['multa_activa' => 1, 'escaloes' => 1]);
    }

    public function test_pedido_sem_campos_de_multa_nao_toca_na_flag_nem_nos_escaloes(): void
    {
        $this->guardar(['escaloes' => [$this->esc(1, 0, '5'), $this->esc(9, 1, '100')]])->assertSessionHasNoErrors();

        $this->actingAs($this->admin())->put(self::URL, $this->semCamposDeMulta(['dia_vencimento' => 12]))
            ->assertSessionHasNoErrors();

        $regra = RegraCobranca::doTenant();
        $this->assertSame(12, $regra->dia_vencimento);
        $this->assertTrue($regra->multa_activa);
        $this->assertSame([500, 10000], $regra->escaloes->pluck('valor')->all());
    }

    public function test_escaloes_sem_multa_activa_no_pedido_sao_ignorados(): void
    {
        $this->actingAs($this->admin())->put(self::URL, $this->semCamposDeMulta() + ['escaloes' => [$this->esc(1, 0, '5')]])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, EscalaoMulta::count());
        $this->assertFalse(RegraCobranca::doTenant()->multa_activa);
    }

    public function test_multa_inactiva_mantem_os_escaloes_gravados_e_ignora_os_enviados(): void
    {
        $this->guardar(['escaloes' => [$this->esc(1, 0, '5'), $this->esc(9, 1, '100')]])->assertSessionHasNoErrors();

        $this->guardar(['multa_activa' => false, 'escaloes' => [['dias_atraso' => '', 'tipo' => 0, 'valor' => 'lixo'], $this->esc(0, 7, '')]])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->guardar(['multa_activa' => false, 'escaloes' => []])->assertSessionHasNoErrors();
        $this->guardar(['multa_activa' => false])->assertSessionHasNoErrors();

        $regra = RegraCobranca::doTenant();
        $this->assertFalse($regra->multa_activa);
        $this->assertSame([1, 9], $regra->escaloes->pluck('dias_atraso')->all());
        $this->assertSame([500, 10000], $regra->escaloes->pluck('valor')->all());
    }

    public function test_reactivar_a_multa_sem_enviar_escaloes_falha(): void
    {
        $this->guardar(['multa_activa' => false])->assertSessionHasNoErrors();
        $this->guardar(['multa_activa' => true, 'escaloes' => null])->assertSessionHasErrors('escaloes');
    }

    public function test_substitui_os_escaloes_anteriores(): void
    {
        $this->guardar(['escaloes' => [$this->esc(1, 0, '5'), $this->esc(10, 0, '8')]])->assertSessionHasNoErrors();
        $this->guardar(['escaloes' => [$this->esc(3, 1, '100')]])->assertSessionHasNoErrors();

        $escaloes = RegraCobranca::doTenant()->escaloes;
        $this->assertCount(1, $escaloes);
        $this->assertSame(3, $escaloes[0]->dias_atraso);
        $this->assertSame(1, $escaloes[0]->ordem);
        $this->assertSame(10000, $escaloes[0]->valor);
    }

    public function test_falha_a_meio_reverte_regra_e_escaloes(): void
    {
        $this->guardar(['escaloes' => [$this->esc(1, 0, '5')]])->assertSessionHasNoErrors();

        EscalaoMulta::creating(function (EscalaoMulta $e) {
            if ($e->ordem === 2) {
                throw new RuntimeException('falha injectada');
            }
        });

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->admin())->put(self::URL, $this->payload([
                'dia_vencimento' => 22,
                'escaloes' => [$this->esc(2, 0, '9'), $this->esc(9, 0, '10')],
            ]));
            $this->fail('devia ter lançado');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $regra = RegraCobranca::doTenant();
        $this->assertSame(10, $regra->dia_vencimento);
        $this->assertSame([1], $regra->escaloes->pluck('dias_atraso')->all());
        $this->assertSame([500], $regra->escaloes->pluck('valor')->all());
    }

    public function test_professor_nao_pode_editar(): void
    {
        $this->actingAs($this->utilizadorCom(Perfil::PROFESSOR))->put(self::URL, $this->payload())->assertForbidden();

        $this->assertSame(0, EscalaoMulta::count());
    }

    public function test_tenant_e_regra_forjados_sao_ignorados_e_o_outro_tenant_nao_e_tocado(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $regraDoOutro = $this->noTenant($outro, function () {
            $regra = RegraCobranca::doTenant();
            $regra->escaloes()->create(['ordem' => 1, 'dias_atraso' => 4, 'tipo' => TipoMulta::PERCENTAGEM, 'valor' => 300]);

            return $regra;
        });

        $this->guardar(['escaloes' => [
            ['dias_atraso' => 1, 'tipo' => 0, 'valor' => '5', 'tenant_id' => $outro->id, 'regra_cobranca_id' => $regraDoOutro->id],
        ]])->assertSessionHasNoErrors();

        $this->assertSame(1, EscalaoMulta::count());
        $this->assertSame($this->tenant->id, EscalaoMulta::first()->tenant_id);
        $this->assertSame(RegraCobranca::doTenant()->id, EscalaoMulta::first()->regra_cobranca_id);
        $delaOutro = DB::table('escaloes_multa')->where('tenant_id', $outro->id)->get();
        $this->assertCount(1, $delaOutro);
        $this->assertSame(4, $delaOutro[0]->dias_atraso);
        $this->assertSame($regraDoOutro->id, $delaOutro[0]->regra_cobranca_id);
    }

    public function test_precos_das_multas_so_contam_valor_fixo_e_bloqueiam_a_moeda(): void
    {
        $fonte = app(PrecosDasMultas::class);
        $this->assertFalse($fonte->existemPrecos());

        $this->guardar(['escaloes' => [$this->esc(1, 0, '5')]])->assertSessionHasNoErrors();
        $this->assertFalse($fonte->existemPrecos());
        $this->assertFalse(app(FontesDePrecos::class)->existem());

        $this->guardar(['escaloes' => [$this->esc(1, 1, '100')]])->assertSessionHasNoErrors();
        $this->assertTrue($fonte->existemPrecos());
        $this->assertTrue(app(FontesDePrecos::class)->existem());
    }

    public function test_a_tela_recebe_multa_escaloes_e_moeda_sem_tenant_id(): void
    {
        $this->guardar(['escaloes' => [$this->esc(1, 0, '2,5'), $this->esc(7, 1, '1500,5')]])->assertSessionHasNoErrors();

        $this->actingAs($this->admin())->get(self::URL)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('regra.multa_activa', true)
            ->where('moeda.codigo', 'AOA')
            ->where('moeda.decimais', 2)
            ->has('escaloes', 2)
            ->missing('regra.escaloes')
            ->where('escaloes.0', ['ordem' => 1, 'dias_atraso' => 1, 'tipo' => 0, 'valor_input' => '2,5'])
            ->where('escaloes.1', ['ordem' => 2, 'dias_atraso' => 7, 'tipo' => 1, 'valor_input' => '1500.50'])
            ->missing('regra.tenant_id')
            ->missing('escaloes.0.tenant_id'));
    }

    public function test_a_tela_sem_escaloes_recebe_lista_vazia(): void
    {
        $this->actingAs($this->admin())->get(self::URL)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('regra.multa_activa', false)
            ->where('escaloes', []));
    }
}
