<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Actions\CancelarPropinaAction;
use Modules\Financeiro\DTO\CancelarPropinaDTO;
use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
use Modules\Financeiro\Enums\AmbitoGeracaoPropinas;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\GerarPropinasService;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class CancelarPropinaTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    private const ROTA = 'financeiro.propinas.cancelar';

    private const LISTA = '/financeiro/propinas';

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

    private function cancelar(Propina $propina, array $dados = ['motivo' => 'Isenção decidida pela direcção'])
    {
        return $this->from(self::LISTA)->patch(route(self::ROTA, $propina->id), $dados);
    }

    public function test_cancela_uma_propina_em_aberto_sem_pagamentos(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano, 2);
        $admin = $this->adminEscola();
        $this->actingAs($admin);

        $this->cancelar($propina)->assertRedirect(self::LISTA)->assertSessionHas('success', 'Propina cancelada.');

        $propina->refresh();
        $this->assertSame(EstadoCobranca::CANCELADA, $propina->estado);
        $this->assertSame('Isenção decidida pela direcção', $propina->motivo_cancelamento);
        $this->assertSame($admin->id, (int) $propina->cancelado_por);
        $this->assertNotNull($propina->cancelado_em);
        $this->assertPropinasCoerentes();
    }

    public function test_cancela_tambem_as_que_se_resolvem_pendente_ou_em_atraso(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $emAtraso = $this->propina($matricula, $plano, 1);
        $pendente = $this->propina($matricula, $plano, 10);
        $this->actingAs($this->adminEscola());

        $this->cancelar($emAtraso)->assertSessionHasNoErrors();
        $this->cancelar($pendente)->assertSessionHasNoErrors();

        $this->assertSame(EstadoCobranca::CANCELADA, $emAtraso->refresh()->estado);
        $this->assertSame(EstadoCobranca::CANCELADA, $pendente->refresh()->estado);
    }

    public function test_parcial_paga_cancelada_e_anulada_dao_erro_e_nada_muda(): void
    {
        $estados = $this->cenarioDeEstados();
        $this->actingAs($this->adminEscola());

        foreach (['out_parcial', 'nov_paga', 'dez_cancelada', 'jan_anulada'] as $chave) {
            $antes = $estados[$chave]->refresh()->only(['estado', 'motivo_cancelamento', 'cancelado_em', 'valor_pago']);

            $this->cancelar($estados[$chave])->assertSessionHasErrors('propina');

            $this->assertEquals($antes, $estados[$chave]->refresh()->only(['estado', 'motivo_cancelamento', 'cancelado_em', 'valor_pago']), $chave);
        }

        $this->assertPropinasCoerentes();
    }

    public function test_com_historico_de_pagamentos_da_erro_e_aponta_para_anular(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);
        $this->historicoPagamentoFalso()->marcar($propina);
        $this->actingAs($this->adminEscola());

        $this->cancelar($propina)->assertSessionHasErrors(['propina' => 'A propina já recebeu pagamentos (entretanto anulados): não se cancela, anula-se (disponível com o módulo de Pagamentos).']);
        $this->assertSame(EstadoCobranca::EM_ABERTO, $propina->refresh()->estado);
    }

    public function test_motivo_obrigatorio_com_limites(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);
        $this->actingAs($this->adminEscola());

        $this->cancelar($propina, [])->assertSessionHasErrors('motivo');
        $this->cancelar($propina, ['motivo' => '   '])->assertSessionHasErrors('motivo');
        $this->cancelar($propina, ['motivo' => 'abc'])->assertSessionHasErrors('motivo');
        $this->cancelar($propina, ['motivo' => str_repeat('x', 501)])->assertSessionHasErrors('motivo');

        $this->assertSame(EstadoCobranca::EM_ABERTO, $propina->refresh()->estado);
    }

    public function test_sem_permissao_de_cancelar_da_403(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);
        $this->actingAs($this->professor());

        $this->cancelar($propina)->assertForbidden();
        $this->assertSame(EstadoCobranca::EM_ABERTO, $propina->refresh()->estado);
    }

    public function test_propina_de_outra_escola_da_404(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $dela = $this->noTenant($outro, function () {
            ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

            return $this->propina($matricula, $plano)->id;
        });
        $this->actingAs($this->adminEscola());

        $this->from(self::LISTA)->patch(route(self::ROTA, $dela), ['motivo' => 'Motivo válido'])->assertNotFound();
        $this->assertSame(EstadoCobranca::EM_ABERTO, $this->noTenant($outro, fn () => Propina::query()->find($dela)->estado));
    }

    public function test_decide_sobre_a_linha_bloqueada_e_nao_sobre_o_model_recebido(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $desactualizada = $this->propina($matricula, $plano);
        $this->comEstado(Propina::query()->find($desactualizada->id), EstadoCobranca::PAGA);

        try {
            app(CancelarPropinaAction::class)->executar($desactualizada, new CancelarPropinaDTO('Motivo válido'), null);
            $this->fail('Devia recusar: a propina já está paga na base de dados.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('propina', $e->errors());
        }

        $this->assertSame(EstadoCobranca::PAGA, $desactualizada->refresh()->estado);
    }

    public function test_depois_de_cancelar_a_geracao_manual_volta_a_gerar_o_periodo(): void
    {
        ['ano' => $ano, 'turma' => $turma, 'plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $setembro = $this->propina($matricula, $plano, 1);
        app(CancelarPropinaAction::class)->executar($setembro, new CancelarPropinaDTO('Lançada por engano'), null);

        $resultado = app(GerarPropinasService::class)->gerar(new PedidoGeracaoPropinasDTO(
            ano_lectivo_id: $ano->id,
            ambito: AmbitoGeracaoPropinas::TURMA,
            origem: OrigemGeracaoPropina::MANUAL,
            alvo_id: $turma->id,
        ));

        $this->assertSame(10, $resultado->totais()['criadas']);
        $this->assertSame(
            [EstadoCobranca::CANCELADA, EstadoCobranca::EM_ABERTO],
            Propina::query()->where('matricula_id', $matricula->id)->where('ordem', 1)->orderBy('id')->get()->pluck('estado')->all(),
        );
        $this->assertPropinasCoerentes();
    }
}
