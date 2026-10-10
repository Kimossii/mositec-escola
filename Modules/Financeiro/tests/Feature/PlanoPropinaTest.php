<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\Core\Enums\Estado;
use Modules\Financeiro\Contracts\ReferenciaFinanceira;
use Modules\Financeiro\Enums\Periodicidade;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\PlanoPropinaAlvo;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Support\FontesDePrecos;
use Modules\Financeiro\Support\PrecosDosPlanos;
use Modules\Financeiro\Support\ReferenciasFinanceiras;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlanoPropinaTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    private const BASE = 'financeiro.configuracao.planos-propina.';

    // A moeda por omissão do tenant de teste é AOA (2 casas decimais); os testes que dependem de
    // outra moeda mudam-na explicitamente (ver test_valor_respeita_as_casas_da_moeda_da_escola).

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function payload(int $anoId, array $sobrepor = []): array
    {
        return array_merge([
            'ano_lectivo_id' => $anoId,
            'nome' => 'Propina Mensal',
            'descricao' => 'Plano regular',
            'periodicidade' => Periodicidade::MENSAL->value,
            'valor' => '25000',
            'mes_inicio' => 9,
            'mes_fim' => 6,
            'alvos' => [],
            'confirmar_plano_geral' => true,
        ], $sobrepor);
    }

    private const MSG_GERAL = 'Este plano não tem alvos definidos e será aplicado a todas as turmas do ano lectivo. Confirme que pretende criar um plano geral.';

    public function test_criar_sem_alvos_e_sem_confirmacao_da_422_e_nao_cria(): void
    {
        $ano = $this->anoLectivo();

        $this->actingAs($this->adminEscola())->from('/x')
            ->post(route(self::BASE . 'store'), $this->payload($ano->id, ['confirmar_plano_geral' => null]))
            ->assertSessionHasErrors(['confirmar_plano_geral' => self::MSG_GERAL]);

        $this->assertSame(0, PlanoPropina::count());
    }

    public function test_criar_sem_alvos_com_confirmacao_cria_plano_geral(): void
    {
        $ano = $this->anoLectivo();

        $this->actingAs($this->adminEscola())
            ->post(route(self::BASE . 'store'), $this->payload($ano->id, ['confirmar_plano_geral' => true]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, PlanoPropina::firstWhere('nome', 'Propina Mensal')->alvos()->count());
    }

    public function test_criar_so_com_linhas_de_alvo_vazias_conta_como_sem_alvos(): void
    {
        $ano = $this->anoLectivo();

        $this->actingAs($this->adminEscola())->from('/x')
            ->post(route(self::BASE . 'store'), $this->payload($ano->id, ['alvos' => [['nivel_academico_id' => null]], 'confirmar_plano_geral' => null]))
            ->assertSessionHasErrors('confirmar_plano_geral');
    }

    public function test_criar_com_alvos_nunca_pede_confirmacao(): void
    {
        $ano = $this->anoLectivo();
        $nivel = $this->nivel('N1');

        $this->actingAs($this->adminEscola())
            ->post(route(self::BASE . 'store'), $this->payload($ano->id, ['alvos' => [['nivel_academico_id' => $nivel->id]]]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, PlanoPropina::count());
    }

    #[DataProvider('confirmacoesForjadas')]
    public function test_confirmacao_falsa_ou_forjada_e_rejeitada(mixed $valor): void
    {
        $ano = $this->anoLectivo();

        $this->actingAs($this->adminEscola())->from('/x')
            ->post(route(self::BASE . 'store'), $this->payload($ano->id, ['confirmar_plano_geral' => $valor]))
            ->assertSessionHasErrors('confirmar_plano_geral');

        $this->assertSame(0, PlanoPropina::count());
    }

    public static function confirmacoesForjadas(): array
    {
        return [[false], ['0'], [0], ['talvez'], [['x']]];
    }

    public function test_actualizar_de_especifico_para_geral_exige_confirmacao(): void
    {
        $ano = $this->anoLectivo();
        $nivel = $this->nivel('N1');
        $plano = $this->plano($ano, 'Propina', [], [['nivel' => $nivel]]);
        $this->actingAs($this->adminEscola());

        $this->from('/x')->put(route(self::BASE . 'update', $plano), $this->payload($ano->id, ['alvos' => [], 'confirmar_plano_geral' => null]))
            ->assertSessionHasErrors(['confirmar_plano_geral' => self::MSG_GERAL]);
        $this->assertSame(1, $plano->alvos()->count());

        $this->put(route(self::BASE . 'update', $plano), $this->payload($ano->id, ['alvos' => [], 'confirmar_plano_geral' => true]))
            ->assertSessionHasNoErrors();
        $this->assertSame(0, $plano->alvos()->count());
    }

    public function test_actualizar_plano_ja_geral_nao_pede_confirmacao_de_novo(): void
    {
        $ano = $this->anoLectivo();
        $plano = $this->plano($ano, 'Geral');

        $this->actingAs($this->adminEscola())
            ->put(route(self::BASE . 'update', $plano), $this->payload($ano->id, ['nome' => 'Geral 2', 'alvos' => []]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Geral 2', $plano->refresh()->nome);
    }

    public function test_actualizar_geral_para_especifico_nao_pede_confirmacao(): void
    {
        $ano = $this->anoLectivo();
        $nivel = $this->nivel('N1');
        $plano = $this->plano($ano, 'Geral');

        $this->actingAs($this->adminEscola())
            ->put(route(self::BASE . 'update', $plano), $this->payload($ano->id, ['alvos' => [['nivel_academico_id' => $nivel->id]]]))
            ->assertSessionHasNoErrors();
    }

    public function test_index_lista_so_os_planos_do_tenant_com_a_forma_esperada(): void
    {
        $ano = $this->anoLectivo();
        $nivel = $this->nivel('N1');
        $curso = $this->curso('C1');
        $turno = $this->turno('Noite');
        $this->plano($ano, 'Propina Mensal', [], [['nivel' => $nivel, 'curso' => $curso, 'turno' => $turno]]);
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, fn () => $this->plano($this->anoLectivo(), 'Do Outro'));

        $this->actingAs($this->adminEscola())
            ->get(route(self::BASE . 'index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Financeiro/PlanosPropina/Index')
                ->has('planos.data', 1)
                ->where('planos.data.0.nome', 'Propina Mensal')
                ->where('planos.data.0.ano_lectivo_nome', '2026/2027')
                ->where('planos.data.0.valor', 2_500_000)
                ->where('planos.data.0.periodos_total', 10)
                ->where('planos.data.0.periodicidade_descricao', 'Mensal')
                ->where('planos.data.0.precedencia', 'Curso + Nível + Turno')
                ->where('planos.data.0.alvos.0.nivel_nome', 'Nível N1')
                ->where('planos.data.0.alvos.0.curso_nome', 'Curso C1')
                ->where('planos.data.0.alvos.0.turno_nome', 'Noite')
                ->missing('planos.data.0.tenant_id')
                ->has('anosLectivos', 1)
                ->has('niveis', 1)
                ->has('cursos', 1)
                ->has('turnos', 1)
                ->has('turmas', 0)
                ->has('periodicidades', 6)
                ->where('moeda.codigo', 'AOA')
                ->where('moeda.decimais', 2));
    }

    public function test_index_mostra_geral_quando_nao_ha_alvos(): void
    {
        $this->plano($this->anoLectivo(), 'Geral');

        $this->actingAs($this->adminEscola())->get(route(self::BASE . 'index'))
            ->assertInertia(fn (Assert $page) => $page->where('planos.data.0.precedencia', 'Geral')->has('planos.data.0.alvos', 0));
    }

    public function test_index_filtra_por_ano_estado_e_pesquisa_e_ignora_filtros_invalidos(): void
    {
        $a = $this->anoLectivo('2026/2027');
        $b = $this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31');
        $this->plano($a, 'Mensal A');
        $this->plano($b, 'Anual B', ['estado' => Estado::INATIVO->value]);
        $this->actingAs($this->adminEscola());

        $this->get(route(self::BASE . 'index', ['ano_lectivo_id' => $b->id]))
            ->assertInertia(fn (Assert $p) => $p->has('planos.data', 1)->where('planos.data.0.nome', 'Anual B'));
        $this->get(route(self::BASE . 'index', ['estado' => '0', 'ano_lectivo_id' => 'todos']))
            ->assertInertia(fn (Assert $p) => $p->has('planos.data', 1)->where('planos.data.0.estado_descricao', 'Inativo'));
        $this->get(route(self::BASE . 'index', ['pesquisa' => 'mensal', 'ano_lectivo_id' => 'todos']))
            ->assertInertia(fn (Assert $p) => $p->has('planos.data', 1)->where('planos.data.0.nome', 'Mensal A'));
        $this->get(route(self::BASE . 'index', ['estado' => 'abc', 'ano_lectivo_id' => 'x']))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('planos.data', 2));
        $this->get(route(self::BASE . 'index') . '?ano_lectivo_id=todos&pesquisa[]=x')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('planos.data', 2));
    }

    public function test_index_filtra_por_omissao_pelo_ano_lectivo_activo_e_permite_ver_todos(): void
    {
        $a = $this->anoLectivo('2026/2027');
        $b = $this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31');
        $a->update(['estado' => EstadoAnoLectivo::ENCERRADO]);
        $this->plano($a, 'Mensal A');
        $this->plano($b, 'Mensal B');
        $this->actingAs($this->adminEscola());

        $this->get(route(self::BASE . 'index'))
            ->assertInertia(fn (Assert $p) => $p->has('planos.data', 1)->where('planos.data.0.nome', 'Mensal B')->where('filtros.ano_lectivo_id', $b->id));
        $this->get(route(self::BASE . 'index', ['ano_lectivo_id' => 'todos']))
            ->assertInertia(fn (Assert $p) => $p->has('planos.data', 2));
        $this->get(route(self::BASE . 'index', ['ano_lectivo_id' => $a->id]))
            ->assertInertia(fn (Assert $p) => $p->has('planos.data', 1)->where('planos.data.0.nome', 'Mensal A'));
    }

    public function test_index_sem_ano_lectivo_activo_lista_todos(): void
    {
        $a = $this->anoLectivo('2026/2027');
        $a->update(['estado' => EstadoAnoLectivo::ENCERRADO]);
        $this->plano($a, 'Mensal A');

        $this->actingAs($this->adminEscola())->get(route(self::BASE . 'index'))
            ->assertInertia(fn (Assert $p) => $p->has('planos.data', 1)->where('filtros.ano_lectivo_id', null));
    }

    public function test_cria_plano_mensal_set_a_jun_com_valor_em_unidades_menores_e_autoria(): void
    {
        $ano = $this->anoLectivo();
        $admin = $this->adminEscola();

        $this->actingAs($admin)->post(route(self::BASE . 'store'), $this->payload($ano->id))
            ->assertSessionHasNoErrors()->assertRedirect();

        $plano = PlanoPropina::firstWhere('nome', 'Propina Mensal');
        $this->assertNotNull($plano);
        $this->assertSame(2_500_000, $plano->valor->unidadesMenores()); // 25000 em AOA (2 casas)
        $this->assertSame(1, $plano->intervalo_meses);
        $this->assertSame(9, $plano->mes_inicio);
        $this->assertSame(6, $plano->mes_fim); // inicio > fim: atravessa o ano civil
        $this->assertSame('Mensal', $plano->periodicidade_descricao);
        $this->assertSame('Ativo', $plano->estado_descricao);
        $this->assertSame($this->tenant->id, $plano->tenant_id);
        $this->assertSame($admin->id, $plano->criado_por);
        $this->assertSame(0, $plano->alvos()->count());
    }

    public function test_trimestral_em_dez_meses_e_aceite_e_da_quatro_periodos(): void
    {
        $ano = $this->anoLectivo();

        $this->actingAs($this->adminEscola())
            ->post(route(self::BASE . 'store'), $this->payload($ano->id, ['periodicidade' => Periodicidade::TRIMESTRAL->value]))
            ->assertSessionHasNoErrors();

        $this->assertSame(3, PlanoPropina::firstWhere('nome', 'Propina Mensal')->intervalo_meses);
        $this->assertCount(4, PlanoPropina::firstWhere('nome', 'Propina Mensal')->periodos());
    }

    public function test_outra_periodicidade_exige_intervalo_e_usa_o_indicado(): void
    {
        $ano = $this->anoLectivo();
        $this->actingAs($this->adminEscola())->from('/x');

        $this->post(route(self::BASE . 'store'), $this->payload($ano->id, ['periodicidade' => Periodicidade::OUTRA->value]))
            ->assertSessionHasErrors('intervalo_meses');

        $this->post(route(self::BASE . 'store'), $this->payload($ano->id, ['periodicidade' => Periodicidade::OUTRA->value, 'intervalo_meses' => 5]))
            ->assertSessionHasNoErrors();

        $this->assertSame(5, PlanoPropina::firstWhere('nome', 'Propina Mensal')->intervalo_meses);
    }

    public function test_periodicidade_maior_que_o_periodo_e_rejeitada(): void
    {
        $ano = $this->anoLectivo();

        $this->actingAs($this->adminEscola())->from('/x')
            ->post(route(self::BASE . 'store'), $this->payload($ano->id, [
                'periodicidade' => Periodicidade::TRIMESTRAL->value, 'mes_inicio' => 1, 'mes_fim' => 2,
            ]))
            ->assertSessionHasErrors(['periodicidade' => 'A periodicidade excede a duração do período de cobrança.']);

        $this->assertSame(0, PlanoPropina::count());
    }

    #[DataProvider('camposInvalidos')]
    public function test_campos_invalidos_sao_rejeitados(string $campo, mixed $valor): void
    {
        $ano = $this->anoLectivo();

        $this->actingAs($this->adminEscola())->from('/x')
            ->post(route(self::BASE . 'store'), $this->payload($ano->id, [$campo => $valor]))
            ->assertSessionHasErrors($campo);

        $this->assertSame(0, PlanoPropina::count());
    }

    public static function camposInvalidos(): array
    {
        return [
            'mes_inicio 0' => ['mes_inicio', 0],
            'mes_inicio 13' => ['mes_inicio', 13],
            'mes_fim 0' => ['mes_fim', 0],
            'mes_fim 13' => ['mes_fim', 13],
            'valor zero' => ['valor', '0'],
            'valor vazio' => ['valor', ''],
            'valor negativo' => ['valor', '-1'],
            'valor três casas' => ['valor', '12.345'],
            'valor com milhares' => ['valor', '25.000,50'],
            'nome vazio' => ['nome', ''],
            'periodicidade inexistente' => ['periodicidade', 99],
            'ano inexistente' => ['ano_lectivo_id', 999999],
        ];
    }

    public function test_valor_com_virgula_e_gravado_em_unidades_menores_exactas(): void
    {
        $ano = $this->anoLectivo();

        $this->actingAs($this->adminEscola())
            ->post(route(self::BASE . 'store'), $this->payload($ano->id, ['valor' => '25000,50'])) // AOA: 2 casas
            ->assertSessionHasNoErrors();

        $this->assertSame(2_500_050, PlanoPropina::firstWhere('nome', 'Propina Mensal')->valor->unidadesMenores());
    }

    public function test_valor_json_inteiro_gigante_ou_float_com_demasiadas_casas_da_422_sem_criar(): void
    {
        $ano = $this->anoLectivo();
        $admin = $this->actingAs($this->adminEscola());

        foreach ([99999999999999999999, 1.23456] as $valor) {
            $admin->postJson(route(self::BASE . 'store'), $this->payload($ano->id, ['valor' => $valor]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('valor');
        }

        $this->assertSame(0, PlanoPropina::count());
    }

    public function test_valor_respeita_as_casas_da_moeda_da_escola(): void
    {
        $ano = $this->anoLectivo();
        ConfiguracaoMonetaria::doTenant()->update(['moeda' => 'JPY']); // 0 casas decimais
        $admin = $this->adminEscola();

        $this->actingAs($admin)->from('/x')
            ->post(route(self::BASE . 'store'), $this->payload($ano->id, ['valor' => '25000,5']))
            ->assertSessionHasErrors('valor');
        $this->assertSame(0, PlanoPropina::count());

        $this->actingAs($admin)
            ->post(route(self::BASE . 'store'), $this->payload($ano->id, ['valor' => '25000']))
            ->assertSessionHasNoErrors();
        $this->assertSame(25000, PlanoPropina::firstWhere('nome', 'Propina Mensal')->valor->unidadesMenores());
    }

    public function test_precos_dos_planos_so_existem_com_planos_e_bloqueiam_a_troca_de_moeda(): void
    {
        $this->assertFalse(app(PrecosDosPlanos::class)->existemPrecos());
        $this->assertFalse(app(FontesDePrecos::class)->existem());
        $this->assertTrue(app(MoedaDoTenant::class)->podeAlterar());

        $this->plano($this->anoLectivo(), 'Propina Mensal', ['estado' => Estado::INATIVO->value]); // inactivo também conta

        $this->assertTrue(app(PrecosDosPlanos::class)->existemPrecos());
        $this->assertTrue(app(FontesDePrecos::class)->existem()); // a fonte está registada com a etiqueta
        $this->assertFalse(app(MoedaDoTenant::class)->podeAlterar());
    }

    public function test_precos_dos_planos_ignoram_planos_de_outro_tenant(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, fn () => $this->plano($this->anoLectivo(), 'Do Outro'));

        $this->assertFalse(app(PrecosDosPlanos::class)->existemPrecos());
    }

    public function test_nome_unico_por_ano_mas_repetivel_noutro_ano_e_noutro_tenant(): void
    {
        $a = $this->anoLectivo('2026/2027');
        $b = $this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31');
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, fn () => $this->plano($this->anoLectivo(), 'Propina Mensal'));
        $this->actingAs($this->adminEscola());

        $this->post(route(self::BASE . 'store'), $this->payload($a->id))->assertSessionHasNoErrors();
        $this->post(route(self::BASE . 'store'), $this->payload($b->id))->assertSessionHasNoErrors();
        $this->from('/x')->post(route(self::BASE . 'store'), $this->payload($a->id))->assertSessionHasErrors('nome');

        $this->assertSame(2, PlanoPropina::count());
    }

    public function test_ano_lectivo_de_outro_tenant_e_rejeitado(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $anoDoOutro = $this->noTenant($outro, fn () => $this->anoLectivo());

        $this->actingAs($this->adminEscola())->from('/x')
            ->post(route(self::BASE . 'store'), $this->payload($anoDoOutro->id))
            ->assertSessionHasErrors('ano_lectivo_id');

        $this->assertSame(0, PlanoPropina::count());
    }

    public function test_alvos_de_outro_tenant_sao_rejeitados(): void
    {
        $ano = $this->anoLectivo();
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        [$nivelDoOutro, $cursoDoOutro, $turnoDoOutro, $turmaDoOutro] = $this->noTenant($outro, function () {
            $ano = $this->anoLectivo();
            $nivel = $this->nivel('NX');

            return [$nivel, $this->curso('CX'), $this->turno('TX'), $this->turma($ano, $nivel)];
        });
        $this->actingAs($this->adminEscola())->from('/x');

        $this->post(route(self::BASE . 'store'), $this->payload($ano->id, ['alvos' => [['nivel_academico_id' => $nivelDoOutro->id]]]))
            ->assertSessionHasErrors('alvos.0.nivel_academico_id');
        $this->post(route(self::BASE . 'store'), $this->payload($ano->id, ['alvos' => [['curso_id' => $cursoDoOutro->id]]]))
            ->assertSessionHasErrors('alvos.0.curso_id');
        $this->post(route(self::BASE . 'store'), $this->payload($ano->id, ['alvos' => [['turno_id' => $turnoDoOutro->id]]]))
            ->assertSessionHasErrors('alvos.0.turno_id');
        $this->post(route(self::BASE . 'store'), $this->payload($ano->id, ['alvos' => [['turma_id' => $turmaDoOutro->id]]]))
            ->assertSessionHasErrors('alvos.0.turma_id');

        $this->assertSame(0, PlanoPropina::count());
    }

    public function test_cria_com_alvos_de_nivel_curso_e_turno_e_ignora_linhas_vazias(): void
    {
        $ano = $this->anoLectivo();
        $nivel = $this->nivel('N1');
        $curso = $this->curso('C1');
        $turno = $this->turno('Noite');

        $this->actingAs($this->adminEscola())
            ->post(route(self::BASE . 'store'), $this->payload($ano->id, ['alvos' => [
                ['nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'turno_id' => $turno->id],
                ['nivel_academico_id' => null, 'curso_id' => null, 'turno_id' => null, 'turma_id' => null],
            ]]))
            ->assertSessionHasNoErrors();

        $plano = PlanoPropina::firstWhere('nome', 'Propina Mensal');
        $this->assertSame(1, $plano->alvos()->count());
        $alvo = $plano->alvos()->first();
        $this->assertSame([$nivel->id, $curso->id, $turno->id, null], [$alvo->nivel_academico_id, $alvo->curso_id, $alvo->turno_id, $alvo->turma_id]);
    }

    public function test_cria_com_turma_especifica_do_mesmo_ano(): void
    {
        $ano = $this->anoLectivo();
        $turma = $this->turma($ano, $this->nivel('N1'));

        $this->actingAs($this->adminEscola())
            ->post(route(self::BASE . 'store'), $this->payload($ano->id, ['alvos' => [['turma_id' => $turma->id]]]))
            ->assertSessionHasNoErrors();

        $this->assertSame($turma->id, PlanoPropina::firstWhere('nome', 'Propina Mensal')->alvos()->first()->turma_id);
    }

    public function test_turma_de_outro_ano_lectivo_e_rejeitada(): void
    {
        $a = $this->anoLectivo('2026/2027');
        $b = $this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31');
        $turmaDoB = $this->turma($b, $this->nivel('N1'));

        $this->actingAs($this->adminEscola())->from('/x')
            ->post(route(self::BASE . 'store'), $this->payload($a->id, ['alvos' => [['turma_id' => $turmaDoB->id]]]))
            ->assertSessionHasErrors(['alvos' => 'A turma escolhida não pertence ao ano lectivo do plano.']);
    }

    public function test_turma_combinada_com_outros_campos_e_rejeitada(): void
    {
        $ano = $this->anoLectivo();
        $nivel = $this->nivel('N1');
        $turma = $this->turma($ano, $nivel);

        $this->actingAs($this->adminEscola())->from('/x')
            ->post(route(self::BASE . 'store'), $this->payload($ano->id, ['alvos' => [['turma_id' => $turma->id, 'nivel_academico_id' => $nivel->id]]]))
            ->assertSessionHasErrors(['alvos' => 'Uma turma específica não se combina com nível, curso ou turno.']);
    }

    public function test_alvos_repetidos_no_payload_sao_rejeitados(): void
    {
        $ano = $this->anoLectivo();
        $nivel = $this->nivel('N1');

        $this->actingAs($this->adminEscola())->from('/x')
            ->post(route(self::BASE . 'store'), $this->payload($ano->id, ['alvos' => [
                ['nivel_academico_id' => $nivel->id], ['nivel_academico_id' => $nivel->id],
            ]]))
            ->assertSessionHasErrors(['alvos' => 'Há alvos repetidos no plano.']);
    }

    public function test_mesmos_alvos_com_periodos_sobrepostos_colidem(): void
    {
        $ano = $this->anoLectivo();
        $nivel = $this->nivel('N1');
        $this->plano($ano, 'Existente', ['mes_inicio' => 9, 'mes_fim' => 12], [['nivel' => $nivel]]);
        $this->actingAs($this->adminEscola())->from('/x');

        $this->post(route(self::BASE . 'store'), $this->payload($ano->id, [
            'nome' => 'Novo', 'mes_inicio' => 12, 'mes_fim' => 6, 'alvos' => [['nivel_academico_id' => $nivel->id]],
        ]))->assertSessionHasErrors(['alvos' => 'Já existe outro plano deste ano lectivo, com período sobreposto, aplicável aos mesmos alvos.']);
    }

    public function test_fases_de_preco_mesmos_alvos_com_periodos_disjuntos_coexistem(): void
    {
        $ano = $this->anoLectivo();
        $nivel = $this->nivel('N1');
        $curso = $this->curso('C1');
        $this->actingAs($this->adminEscola());

        $this->post(route(self::BASE . 'store'), $this->payload($ano->id, [
            'nome' => 'Fase 1', 'mes_inicio' => 9, 'mes_fim' => 12, 'valor' => '30000',
            'alvos' => [['nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id]],
        ]))->assertSessionHasNoErrors();
        $this->post(route(self::BASE . 'store'), $this->payload($ano->id, [
            'nome' => 'Fase 2', 'mes_inicio' => 1, 'mes_fim' => 6, 'valor' => '35000',
            'alvos' => [['nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id]],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(2, PlanoPropina::count());
    }

    public function test_dois_planos_gerais_colidem_se_os_periodos_se_sobrepoem_mas_nao_se_forem_disjuntos(): void
    {
        $ano = $this->anoLectivo();
        $this->plano($ano, 'Geral Fase 1', ['mes_inicio' => 9, 'mes_fim' => 12]);
        $this->actingAs($this->adminEscola());

        $this->from('/x')->post(route(self::BASE . 'store'), $this->payload($ano->id, ['nome' => 'Geral Sobreposto']))
            ->assertSessionHasErrors('alvos');

        $this->post(route(self::BASE . 'store'), $this->payload($ano->id, ['nome' => 'Geral Fase 2', 'mes_inicio' => 1, 'mes_fim' => 6]))
            ->assertSessionHasNoErrors();
    }

    public function test_mesmos_alvos_noutro_ano_lectivo_nao_colidem(): void
    {
        $a = $this->anoLectivo('2026/2027');
        $b = $this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31');
        $nivel = $this->nivel('N1');
        $this->plano($a, 'Existente', [], [['nivel' => $nivel]]);

        $this->actingAs($this->adminEscola())
            ->post(route(self::BASE . 'store'), $this->payload($b->id, ['nome' => 'Novo', 'alvos' => [['nivel_academico_id' => $nivel->id]]]))
            ->assertSessionHasNoErrors();
    }

    public function test_alvos_diferentes_no_mesmo_ano_coexistem(): void
    {
        $ano = $this->anoLectivo();
        $n1 = $this->nivel('N1');
        $n2 = $this->nivel('N2');
        $this->plano($ano, 'Geral');
        $this->plano($ano, 'Nivel 1', [], [['nivel' => $n1]]);

        $this->actingAs($this->adminEscola())
            ->post(route(self::BASE . 'store'), $this->payload($ano->id, ['nome' => 'Nivel 2', 'alvos' => [['nivel_academico_id' => $n2->id]]]))
            ->assertSessionHasNoErrors();

        $this->assertSame(3, PlanoPropina::count());
    }

    public function test_curso_e_nivel_em_alvos_distintos_do_mesmo_ano_coexistem_porque_a_precedencia_desempata(): void
    {
        $ano = $this->anoLectivo();
        $nivel = $this->nivel('N1');
        $curso = $this->curso('C1');
        $this->plano($ano, 'Por Nivel', [], [['nivel' => $nivel]]);

        $this->actingAs($this->adminEscola())
            ->post(route(self::BASE . 'store'), $this->payload($ano->id, ['nome' => 'Por Curso', 'alvos' => [['curso_id' => $curso->id]]]))
            ->assertSessionHasNoErrors();
    }

    public function test_actualiza_sem_colidir_consigo_substitui_alvos_e_ignora_o_ano(): void
    {
        $a = $this->anoLectivo('2026/2027');
        $b = $this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31');
        $nivel = $this->nivel('N1');
        $curso = $this->curso('C1');
        $plano = $this->plano($a, 'Propina', [], [['nivel' => $nivel]]);

        $this->actingAs($this->adminEscola())
            ->put(route(self::BASE . 'update', $plano), $this->payload($b->id, [
                'nome' => 'Propina Renomeada', 'valor' => '30000', 'periodicidade' => Periodicidade::SEMESTRAL->value,
                'alvos' => [['nivel_academico_id' => $nivel->id], ['curso_id' => $curso->id]],
            ]))
            ->assertSessionHasNoErrors();

        $plano->refresh();
        $this->assertSame('Propina Renomeada', $plano->nome);
        $this->assertSame(3_000_000, $plano->valor->unidadesMenores()); // 30000 em AOA
        $this->assertSame(6, $plano->intervalo_meses);
        $this->assertSame('Semestral', $plano->periodicidade_descricao);
        $this->assertSame($a->id, $plano->ano_lectivo_id); // o ano não muda na edição
        $this->assertSame(2, $plano->alvos()->count());
    }

    public function test_actualizar_valida_a_turma_contra_o_ano_do_plano_e_nao_o_do_payload(): void
    {
        $a = $this->anoLectivo('2026/2027');
        $b = $this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31');
        $turmaDoB = $this->turma($b, $this->nivel('N1'));
        $plano = $this->plano($a, 'Propina');

        $this->actingAs($this->adminEscola())->from('/x')
            ->put(route(self::BASE . 'update', $plano), $this->payload($b->id, ['alvos' => [['turma_id' => $turmaDoB->id]]]))
            ->assertSessionHasErrors(['alvos' => 'A turma escolhida não pertence ao ano lectivo do plano.']);
    }

    public function test_actualizar_com_nome_de_outro_plano_do_mesmo_ano_falha(): void
    {
        $ano = $this->anoLectivo();
        $nivel = $this->nivel('N1');
        $this->plano($ano, 'A');
        $b = $this->plano($ano, 'B', [], [['nivel' => $nivel]]);

        $this->actingAs($this->adminEscola())->from('/x')
            ->put(route(self::BASE . 'update', $b), $this->payload($ano->id, ['nome' => 'A', 'alvos' => [['nivel_academico_id' => $nivel->id]]]))
            ->assertSessionHasErrors('nome');
    }

    public function test_desactiva_e_reactiva(): void
    {
        $plano = $this->plano($this->anoLectivo(), 'A');
        $this->actingAs($this->adminEscola());

        $this->patch(route(self::BASE . 'alterar-estado', $plano), ['estado' => 0])->assertSessionHasNoErrors();
        $this->assertSame('Inativo', $plano->fresh()->estado_descricao);
        $this->assertSame(0, PlanoPropina::activos()->count());

        $this->patch(route(self::BASE . 'alterar-estado', $plano), ['estado' => 1])->assertSessionHasNoErrors();
        $this->assertSame(1, PlanoPropina::activos()->count());
    }

    public function test_elimina_sem_referencias_e_apaga_os_alvos(): void
    {
        $plano = $this->plano($this->anoLectivo(), 'A', [], [['nivel' => $this->nivel('N1')]]);

        $this->actingAs($this->adminEscola())
            ->delete(route(self::BASE . 'destroy', $plano))
            ->assertSessionHasNoErrors();

        $this->assertNull(PlanoPropina::find($plano->id));
        $this->assertSame(0, PlanoPropinaAlvo::count());
    }

    public function test_eliminar_com_referencia_financeira_e_bloqueado(): void
    {
        $plano = $this->plano($this->anoLectivo(), 'A');
        $this->app->instance('ref.fake', new class implements ReferenciaFinanceira {
            public function existeReferenciaA(Model $configuracao): bool
            {
                return true;
            }
        });
        $this->app->tag(['ref.fake'], ReferenciasFinanceiras::ETIQUETA);

        $this->actingAs($this->adminEscola())->from('/x')
            ->delete(route(self::BASE . 'destroy', $plano))
            ->assertSessionHasErrors(['eliminar' => 'Não é possível eliminar este plano de propina: já existem registos financeiros que o utilizam. Desative-o.']);

        $this->assertNotNull(PlanoPropina::find($plano->id));
    }

    public function test_professor_recebe_403_em_todas_as_rotas(): void
    {
        $plano = $this->plano($this->anoLectivo(), 'A');
        $this->actingAs($this->professor());

        $this->get(route(self::BASE . 'index'))->assertForbidden();
        $this->post(route(self::BASE . 'store'), $this->payload($plano->ano_lectivo_id))->assertForbidden();
        $this->put(route(self::BASE . 'update', $plano), $this->payload($plano->ano_lectivo_id))->assertForbidden();
        $this->patch(route(self::BASE . 'alterar-estado', $plano), ['estado' => 0])->assertForbidden();
        $this->delete(route(self::BASE . 'destroy', $plano))->assertForbidden();
    }

    public function test_plano_de_outro_tenant_da_404_e_nada_muda(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $doOutro = $this->noTenant($outro, fn () => $this->plano($this->anoLectivo(), 'Do Outro'));
        $this->actingAs($this->adminEscola());

        $this->put(route(self::BASE . 'update', $doOutro->id), $this->payload($doOutro->ano_lectivo_id, ['nome' => 'Alterado']))->assertNotFound();
        $this->patch(route(self::BASE . 'alterar-estado', $doOutro->id), ['estado' => 0])->assertNotFound();
        $this->delete(route(self::BASE . 'destroy', $doOutro->id))->assertNotFound();

        $linha = DB::table('planos_propina')->where('id', $doOutro->id)->first();
        $this->assertSame('Do Outro', $linha->nome);
        $this->assertSame(1, (int) $linha->estado);
    }

    public function test_tenant_id_forjado_no_payload_e_ignorado(): void
    {
        $ano = $this->anoLectivo();
        $outro = $this->criarTenant('MOSI-000003', 'Escola C', 'c.localhost');

        $this->actingAs($this->adminEscola())
            ->post(route(self::BASE . 'store'), $this->payload($ano->id, ['tenant_id' => $outro->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame($this->tenant->id, PlanoPropina::firstWhere('nome', 'Propina Mensal')->tenant_id);
    }

    public function test_mensagens_ao_utilizador_em_portugues(): void
    {
        $ano = $this->anoLectivo();
        $this->actingAs($this->adminEscola())->from('/x');

        $this->post(route(self::BASE . 'store'), $this->payload($ano->id, ['nome' => '']))
            ->assertSessionHasErrors(['nome' => 'O nome do plano de propina é obrigatório.']);

        $this->post(route(self::BASE . 'store'), $this->payload($ano->id))->assertSessionHas('success', 'Plano de propina criado com sucesso.');
        $plano = PlanoPropina::firstWhere('nome', 'Propina Mensal');

        $this->from('/x')->post(route(self::BASE . 'store'), $this->payload($ano->id))
            ->assertSessionHasErrors(['nome' => 'Já existe um plano de propina com este nome neste ano lectivo.']);

        $this->put(route(self::BASE . 'update', $plano), $this->payload($ano->id))
            ->assertSessionHas('success', 'Plano de propina atualizado com sucesso.');
        $this->patch(route(self::BASE . 'alterar-estado', $plano), ['estado' => 0])
            ->assertSessionHas('success', 'Estado do plano de propina atualizado com sucesso.');
        $this->delete(route(self::BASE . 'destroy', $plano))
            ->assertSessionHas('success', 'Plano de propina eliminado com sucesso.');
    }
}
