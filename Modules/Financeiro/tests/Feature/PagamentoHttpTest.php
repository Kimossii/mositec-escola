<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Financeiro\Actions\RegistarPagamentoAction;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\DistribuicaoDePagamentoService;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Matricula\Models\Matricula;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Modulo;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo as ModuloRegistro;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class PagamentoHttpTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPagamentosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    private Matricula $matricula;

    private Propina $set;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
        ['plano' => $plano, 'matricula' => $this->matricula] = $this->cenarioPropinas();
        $this->set = $this->propina($this->matricula, $plano, 1);
        $this->regras();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array<string, mixed> valores em decimal (moeda da escola, 2 casas) */
    private function corpo(array $sobrepor = []): array
    {
        return array_merge([
            'metodo_pagamento_id' => $this->metodoPagamento()->id,
            'valor' => '25000,00',
            'data_pagamento' => '2026-10-16',
            'referencia' => 'TRF-100',
            'modo_distribuicao' => 'explicita',
            'distribuicao' => [['propina_id' => $this->set->id, 'valor' => '25000,00']],
        ], $sobrepor);
    }

    public function test_todas_as_rotas_exigem_a_ability_certa(): void
    {
        $esperado = [
            'financeiro.pagamentos.index' => 'can:pagamento.ver',
            'financeiro.pagamentos.create' => 'can:pagamento.criar',
            'financeiro.pagamentos.matriculas.pesquisar' => 'can:pagamento.criar',
            'financeiro.pagamentos.matriculas.situacao' => 'can:pagamento.criar',
            'financeiro.pagamentos.pre-visualizar' => 'can:pagamento.criar',
            'financeiro.pagamentos.store' => 'can:pagamento.criar',
            'financeiro.pagamentos.show' => 'can:pagamento.ver',
            'financeiro.pagamentos.recibo' => 'can:pagamento.ver',
            'financeiro.pagamentos.recibo.propina' => 'can:pagamento.ver',
            'financeiro.pagamentos.comprovativo' => 'can:pagamento.ver',
            'financeiro.pagamentos.comprovativo.anexar' => 'can:pagamento.criar',
            'financeiro.pagamentos.anular' => 'can:pagamento.anular',
            'financeiro.creditos.aluno' => 'can:pagamento.ver',
            'financeiro.creditos.pre-visualizar' => 'can:pagamento.criar',
            'financeiro.creditos.aplicar' => 'can:pagamento.criar',
            'financeiro.creditos.utilizacoes.anular' => 'can:pagamento.anular',
        ];

        foreach ($esperado as $nome => $ability) {
            $this->assertContains($ability, Route::getRoutes()->getByName($nome)->gatherMiddleware(), $nome);
        }
    }

    public function test_registar_pela_rota_redirecciona_para_o_detalhe(): void
    {
        $this->actingAs($this->adminEscola());

        $resposta = $this->post(route('financeiro.pagamentos.store', $this->matricula->id), $this->corpo());

        $pagamento = Pagamento::query()->sole();
        $resposta->assertRedirect(route('financeiro.pagamentos.show', $pagamento->id))->assertSessionHas('success', "Pagamento n.º {$pagamento->numero} registado.");
        $this->assertSame(2_500_000, $pagamento->valor->unidadesMenores());
        $this->assertSame(EstadoCobranca::PAGA, $this->set->fresh()->estado);
    }

    public function test_validacao_do_pedido(): void
    {
        $this->actingAs($this->adminEscola());
        $de = route('financeiro.pagamentos.create');

        $this->from($de)->post(route('financeiro.pagamentos.store', $this->matricula->id), $this->corpo(['valor' => '0']))->assertSessionHasErrors('valor');
        $this->from($de)->post(route('financeiro.pagamentos.store', $this->matricula->id), $this->corpo(['data_pagamento' => '2026-10-17']))->assertSessionHasErrors('data_pagamento');
        $this->from($de)->post(route('financeiro.pagamentos.store', $this->matricula->id), $this->corpo(['data_pagamento' => '2026-08-31']))->assertSessionHasErrors('data_pagamento'); // O3: antes da matrícula (01/09/2026)
        $this->from($de)->post(route('financeiro.pagamentos.store', $this->matricula->id), $this->corpo(['metodo_pagamento_id' => $this->metodoPagamento('Velho', activo: false)->id]))->assertSessionHasErrors('metodo_pagamento_id');
        $this->from($de)->post(route('financeiro.pagamentos.store', $this->matricula->id), $this->corpo(['distribuicao' => [
            ['propina_id' => $this->set->id, 'valor' => '1,00'], ['propina_id' => $this->set->id, 'valor' => '1,00'],
        ]]))->assertSessionHasErrors('distribuicao.1.propina_id');
        $this->from($de)->post(route('financeiro.pagamentos.store', $this->matricula->id), $this->corpo(['distribuicao' => [['propina_id' => $this->set->id, 'valor' => '1000,00']]]))->assertSessionHasErrors('distribuicao.0.valor');
        $this->assertSame(0, Pagamento::query()->count());
    }

    /** A Action e o motor repetem a regra da data (sob lock); aqui prova-se que o PEDIDO a recusa antes de lá chegar (O3). */
    public function test_a_data_e_recusada_no_pedido_antes_da_action_e_do_motor(): void
    {
        $this->actingAs($this->adminEscola());
        $this->mock(RegistarPagamentoAction::class)->shouldNotReceive('executar');
        $this->partialMock(DistribuicaoDePagamentoService::class)->shouldNotReceive('preVisualizarPagamento');

        foreach (['2026-10-17', '2026-08-31'] as $data) {
            $this->from(route('financeiro.pagamentos.create'))->post(route('financeiro.pagamentos.store', $this->matricula->id), $this->corpo(['data_pagamento' => $data]))
                ->assertSessionHasErrors('data_pagamento');
            $this->postJson(route('financeiro.pagamentos.pre-visualizar', $this->matricula->id), $this->corpo(['data_pagamento' => $data]))
                ->assertStatus(422)->assertJsonValidationErrors('data_pagamento');
        }
    }

    public function test_pre_visualizar_devolve_o_plano_e_nao_escreve(): void
    {
        $this->actingAs($this->adminEscola());

        $this->postJson(route('financeiro.pagamentos.pre-visualizar', $this->matricula->id), $this->corpo(['valor' => '30000,00', 'modo_distribuicao' => 'automatica']))
            ->assertOk()
            ->assertJsonPath('plano.excedente', 500_000)
            ->assertJsonPath('plano.linhas.0.propina_id', $this->set->id)
            ->assertJsonPath('plano.linhas.0.dinheiro', 2_500_000);
        $this->postJson(route('financeiro.pagamentos.pre-visualizar', $this->matricula->id), $this->corpo(['distribuicao' => []]))
            ->assertOk()->assertJsonPath('plano.linhas', [])->assertJsonPath('plano.excedente', 2_500_000); // explícita vazia = tudo crédito
        $this->postJson(route('financeiro.pagamentos.pre-visualizar', $this->matricula->id), $this->corpo(['data_pagamento' => '2026-10-17']))
            ->assertStatus(422)->assertJsonValidationErrors('data_pagamento');
        $this->postJson(route('financeiro.pagamentos.pre-visualizar', $this->matricula->id), $this->corpo(['data_pagamento' => '2026-08-31']))
            ->assertStatus(422)->assertJsonValidationErrors('data_pagamento');
        $this->postJson(route('financeiro.pagamentos.pre-visualizar', $this->matricula->id), $this->corpo(['data_pagamento' => '2026-09-01']))->assertOk(); // igual à data da matrícula passa
        $this->assertSame(0, Pagamento::query()->count());
    }

    public function test_referencia_usada_num_pagamento_anulado_avisa_sem_bloquear(): void
    {
        $this->actingAs($this->adminEscola());
        $anulado = $this->pagar($this->matricula, 2_500_000, [[$this->set, 2_500_000]], referencia: 'TRF-100');
        $this->anularPagamento($anulado);

        $previa = $this->postJson(route('financeiro.pagamentos.pre-visualizar', $this->matricula->id), $this->corpo())->assertOk()->assertJsonCount(1, 'avisos');
        $this->assertStringContainsString($anulado->numero, $previa->json('avisos.0'));
        $this->postJson(route('financeiro.pagamentos.pre-visualizar', $this->matricula->id), $this->corpo(['referencia' => 'OUTRA']))->assertOk()->assertJsonCount(0, 'avisos');

        $this->post(route('financeiro.pagamentos.store', $this->matricula->id), $this->corpo())->assertSessionHas('success')->assertSessionHas('warning');
        $this->assertSame(1, Pagamento::query()->where('estado', EstadoPagamento::CONFIRMADO->value)->count());
    }

    public function test_pesquisa_situacao_e_paginas(): void
    {
        $this->actingAs($this->adminEscola());

        $this->getJson(route('financeiro.pagamentos.matriculas.pesquisar', ['q' => 'Aluno de']))->assertOk()->assertJsonPath('matriculas.0.id', $this->matricula->id);
        $this->getJson(route('financeiro.pagamentos.matriculas.situacao', $this->matricula->id))->assertOk()->assertJsonPath('situacao.propinas.0.id', $this->set->id);
        $this->get(route('financeiro.pagamentos.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('Financeiro/Pagamentos/Index')->has('pagamentos.data')->where('podeRegistar', true));
        $this->get(route('financeiro.pagamentos.create', ['matricula' => $this->matricula->id]))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Financeiro/Pagamentos/Create')->where('hoje', '2026-10-16')->where('matriculaInicial.id', $this->matricula->id));
    }

    public function test_detalhe_e_anular_pela_rota(): void
    {
        $admin = $this->adminEscola();
        $this->actingAs($admin);
        $pagamento = $this->pagar($this->matricula, 2_500_000, [[$this->set, 2_500_000]]);

        $this->get(route('financeiro.pagamentos.show', $pagamento->id))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Financeiro/Pagamentos/Show')->where('pagamento.numero', $pagamento->numero)->where('podeAnular', true));

        $this->from(route('financeiro.pagamentos.show', $pagamento->id))
            ->patch(route('financeiro.pagamentos.anular', $pagamento->id), ['motivo' => 'abc'])->assertSessionHasErrors('motivo');
        $this->from(route('financeiro.pagamentos.show', $pagamento->id))
            ->patch(route('financeiro.pagamentos.anular', $pagamento->id), ['motivo' => 'Talão em duplicado'])
            ->assertRedirect(route('financeiro.pagamentos.show', $pagamento->id))->assertSessionHas('success', 'Pagamento anulado.');
        $this->assertSame(EstadoPagamento::ANULADO, $pagamento->fresh()->estado);
        $this->assertSame($admin->id, (int) $pagamento->fresh()->anulado_por);
    }

    public function test_creditos_do_aluno_aplicar_e_anular_utilizacao_pela_rota(): void
    {
        $this->actingAs($this->adminEscola());
        $this->regras(parcial: true);
        $out = $this->propina($this->matricula, $this->set->plano, 2);
        $this->pagar($this->matricula, 3_000_000, [[$this->set, 2_500_000]]);

        $this->get(route('financeiro.creditos.aluno', $this->matricula->aluno_id))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Financeiro/Creditos/Aluno')->where('saldo_disponivel', 500_000));
        $this->postJson(route('financeiro.creditos.pre-visualizar', $this->matricula->id), ['valor_credito' => '5000,00', 'modo_distribuicao' => 'automatica'])->assertOk()
            ->assertJsonPath('plano.utilizacoes.0.propina_id', $out->id);
        $this->from(route('financeiro.creditos.aluno', $this->matricula->aluno_id))
            ->post(route('financeiro.creditos.aplicar', $this->matricula->id), ['valor_credito' => '5000,00', 'modo_distribuicao' => 'automatica'])->assertSessionHas('success', 'Crédito aplicado.');
        $utilizacao = $out->utilizacoesDeCredito()->sole();

        $this->from(route('financeiro.creditos.aluno', $this->matricula->aluno_id))
            ->patch(route('financeiro.creditos.utilizacoes.anular', $utilizacao->id), ['motivo' => 'Aplicado à propina errada'])
            ->assertSessionHas('success', 'Utilização de crédito anulada.');
        $this->assertSame(EstadoCobranca::EM_ABERTO, $out->fresh()->estado);
    }

    public function test_professor_nao_entra_e_ids_de_outro_tenant_dao_404(): void
    {
        $pagamento = $this->pagar($this->matricula, 2_500_000, [[$this->set, 2_500_000]]);
        $this->actingAs($this->professor());
        $this->get(route('financeiro.pagamentos.index'))->assertForbidden();
        $this->post(route('financeiro.pagamentos.store', $this->matricula->id), $this->corpo())->assertForbidden();

        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        [$matriculaDela, $pagamentoDela] = $this->noTenant($outro, function () {
            ['plano' => $plano, 'matricula' => $m] = $this->cenarioPropinas();
            $this->regras();

            return [$m, $this->pagar($m, 2_500_000, [[$this->propina($m, $plano, 1), 2_500_000]])];
        });

        $this->actingAs($this->adminEscola());
        $this->get(route('financeiro.pagamentos.show', $pagamentoDela->id))->assertNotFound();
        $this->post(route('financeiro.pagamentos.store', $matriculaDela->id), $this->corpo())->assertNotFound();
        $this->getJson(route('financeiro.pagamentos.matriculas.situacao', $matriculaDela->id))->assertNotFound();
        $this->patch(route('financeiro.pagamentos.anular', $pagamentoDela->id), ['motivo' => 'Tentativa alheia'])->assertNotFound();
        $this->assertSame(EstadoPagamento::CONFIRMADO, $pagamento->fresh()->estado);
    }

    public function test_pre_visualizar_creditos_e_anulacoes_de_outro_tenant_dao_404(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        [$matriculaDela, $propinaDela, $utilizacaoDela] = $this->noTenant($outro, function () {
            ['plano' => $plano, 'matricula' => $m] = $this->cenarioPropinas();
            $this->regras(parcial: true);
            $primeira = $this->propina($m, $plano, 1);
            $segunda = $this->propina($m, $plano, 2);
            $this->pagar($m, 3_000_000, [[$primeira, 2_500_000]]);

            return [$m, $segunda, $this->aplicarCredito($segunda, 300_000)];
        });

        $this->actingAs($this->adminEscola());
        $json = ['valor_credito' => '1,00', 'modo_distribuicao' => 'automatica'];

        $this->postJson(route('financeiro.pagamentos.pre-visualizar', $matriculaDela->id), $this->corpo())->assertNotFound();
        $this->getJson(route('financeiro.creditos.aluno', $matriculaDela->aluno_id))->assertNotFound();
        $this->postJson(route('financeiro.creditos.pre-visualizar', $matriculaDela->id), $json)->assertNotFound();
        $this->postJson(route('financeiro.creditos.aplicar', $matriculaDela->id), $json)->assertNotFound();
        $this->patchJson(route('financeiro.creditos.utilizacoes.anular', $utilizacaoDela->id), ['motivo' => 'Tentativa alheia'])->assertNotFound();
        $this->patchJson(route('financeiro.propinas.anular', $propinaDela->id), ['motivo' => 'Tentativa alheia'])->assertNotFound();

        $this->noTenant($outro, fn () => $this->assertSame('Activa', $utilizacaoDela->fresh()->estado->label()));
    }

    public function test_nao_existe_edicao_nem_eliminacao(): void
    {
        $this->actingAs($this->adminEscola());
        $pagamento = $this->pagar($this->matricula, 2_500_000, [[$this->set, 2_500_000]]);

        $this->put('/financeiro/pagamentos/' . $pagamento->id, $this->corpo())->assertStatus(405);
        $this->delete('/financeiro/pagamentos/' . $pagamento->id)->assertStatus(405);
    }

    /** Perfil personalizado só com as acções indicadas do módulo Pagamento (padrão de PermissoesPorRecursoTest). */
    private function utilizadorSoCom(string ...$acoes): User
    {
        $role = Role::create(['nome' => 100 + count($acoes) + crc32(implode(',', $acoes)) % 1000, 'descricao' => 'Só ' . implode(',', $acoes)]);
        $registo = ModuloRegistro::where('nome', Modulo::PAGAMENTO->value)->firstOrFail();

        foreach (Acao::whereIn('nome', $acoes)->get() as $acao) {
            RolePermissao::create(['role_id' => $role->id, 'modulo_id' => $registo->id, 'acao_id' => $acao->id]);
        }

        $user = User::create(['name' => 'Teste', 'email' => 'so-' . implode('-', $acoes) . '@example.com', 'password' => Hash::make('x')]);
        $user->roles()->attach($role->id);

        return $user;
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> rota => [método, ability, parâmetros base] */
    private function rotasDeEscritaELeitura(Pagamento $pagamento, int $utilizacaoId): array
    {
        $m = $this->matricula->id;

        return [
            'pagamentos.index' => ['get', 'ver', route('financeiro.pagamentos.index')],
            'pagamentos.show' => ['get', 'ver', route('financeiro.pagamentos.show', $pagamento->id)],
            'creditos.aluno' => ['get', 'ver', route('financeiro.creditos.aluno', $this->matricula->aluno_id)],
            'pagamentos.create' => ['get', 'criar', route('financeiro.pagamentos.create')],
            'pagamentos.pesquisar' => ['get', 'criar', route('financeiro.pagamentos.matriculas.pesquisar', ['q' => 'Aluno'])],
            'pagamentos.situacao' => ['get', 'criar', route('financeiro.pagamentos.matriculas.situacao', $m)],
            'pagamentos.pre-visualizar' => ['post', 'criar', route('financeiro.pagamentos.pre-visualizar', $m)],
            'pagamentos.store' => ['post', 'criar', route('financeiro.pagamentos.store', $m)],
            'creditos.pre-visualizar' => ['post', 'criar', route('financeiro.creditos.pre-visualizar', $m)],
            'creditos.aplicar' => ['post', 'criar', route('financeiro.creditos.aplicar', $m)],
            'pagamentos.anular' => ['patch', 'anular', route('financeiro.pagamentos.anular', $pagamento->id)],
            'utilizacoes.anular' => ['patch', 'anular', route('financeiro.creditos.utilizacoes.anular', $utilizacaoId)],
        ];
    }

    public function test_cada_accao_so_abre_as_suas_rotas(): void
    {
        $this->regras(parcial: true);
        $pagamento = $this->pagar($this->matricula, 3_000_000, [[$this->set, 2_500_000]]);
        $utilizacao = $this->aplicarCredito($this->propina($this->matricula, $this->set->plano, 2), 100_000);
        $rotas = $this->rotasDeEscritaELeitura($pagamento, $utilizacao->id);

        foreach (['ver', 'criar', 'anular'] as $acao) {
            $this->actingAs($this->utilizadorSoCom($acao));

            foreach ($rotas as $nome => [$metodo, $exige, $url]) {
                $estado = $this->call(strtoupper($metodo), $url, [], [], [], ['HTTP_ACCEPT' => 'application/json'])->getStatusCode();

                if ($exige === $acao) {
                    $this->assertNotSame(403, $estado, "{$nome} devia abrir para só '{$acao}'.");
                } else {
                    $this->assertSame(403, $estado, "{$nome} devia recusar quem só tem '{$acao}'.");
                }
            }
        }

        $this->actingAs($this->professor());

        foreach ($rotas as $nome => [$metodo, , $url]) {
            $this->call(strtoupper($metodo), $url, [], [], [], ['HTTP_ACCEPT' => 'application/json'])->assertForbidden();
        }
    }

    public function test_ids_nao_numericos_nao_chegam_ao_controller(): void
    {
        // Em SQLite o binding de 'abc' dá 404 por si só; em PostgreSQL daria 500 (bigint). Por isso a restrição da própria rota é que se prova.
        foreach ([
            'financeiro.pagamentos.matriculas.situacao' => 'matricula', 'financeiro.pagamentos.pre-visualizar' => 'matricula',
            'financeiro.pagamentos.store' => 'matricula', 'financeiro.pagamentos.show' => 'pagamento', 'financeiro.pagamentos.anular' => 'pagamento',
            'financeiro.pagamentos.comprovativo' => 'pagamento', 'financeiro.pagamentos.comprovativo.anexar' => 'pagamento',
            'financeiro.creditos.aluno' => 'aluno', 'financeiro.creditos.pre-visualizar' => 'matricula', 'financeiro.creditos.aplicar' => 'matricula',
            'financeiro.creditos.utilizacoes.anular' => 'utilizacao',
        ] as $nome => $parametro) {
            $this->assertSame('[0-9]+', Route::getRoutes()->getByName($nome)->wheres[$parametro] ?? null, "{$nome}: falta whereNumber('{$parametro}')");
        }

        $this->actingAs($this->adminEscola());

        foreach ([
            ['get', '/financeiro/pagamentos/abc'],
            ['patch', '/financeiro/pagamentos/abc/anular'],
            ['get', '/financeiro/pagamentos/abc/comprovativo'],
            ['post', '/financeiro/pagamentos/abc/comprovativo'],
            ['get', '/financeiro/pagamentos/matriculas/abc/situacao'],
            ['post', '/financeiro/pagamentos/matriculas/abc/pre-visualizar'],
            ['post', '/financeiro/pagamentos/matriculas/abc'],
            ['get', '/financeiro/creditos/alunos/abc'],
            ['post', '/financeiro/creditos/matriculas/abc/pre-visualizar'],
            ['post', '/financeiro/creditos/matriculas/abc/aplicar'],
            ['patch', '/financeiro/creditos/utilizacoes/abc/anular'],
        ] as [$metodo, $url]) {
            $this->call(strtoupper($metodo), $url, [], [], [], ['HTTP_ACCEPT' => 'application/json'])->assertNotFound();
        }
    }
}
