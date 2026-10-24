<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Support\CancelamentoDePropinas;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class CancelamentoDePropinasTest extends TestCase
{
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

    private function cancelamento(): CancelamentoDePropinas
    {
        return app(CancelamentoDePropinas::class);
    }

    public function test_em_aberto_pendente_e_em_atraso_sem_pagamentos_sao_cancelaveis(): void
    {
        $estados = $this->cenarioDeEstados();

        // set_aberta resolve-se Em Atraso (limite 15/09) e jun_aberta Pendente: ambas persistidas Em Aberto.
        foreach (['set_aberta', 'fev_aberta', 'mai_aberta', 'jun_aberta'] as $chave) {
            $this->assertNull($this->cancelamento()->motivoParaNaoCancelar($estados[$chave]), $chave);
        }

        $this->assertSame(EstadoCobranca::EM_ATRASO, $estados['set_aberta']->estadoResolvido(CarbonImmutable::parse('2026-10-16')));
        $this->assertSame(EstadoCobranca::PENDENTE, $estados['jun_aberta']->estadoResolvido(CarbonImmutable::parse('2026-10-16')));
    }

    public function test_parcial_paga_cancelada_e_anulada_nao_sao_cancelaveis_e_cada_uma_diz_porque(): void
    {
        $estados = $this->cenarioDeEstados();

        $this->assertStringContainsString('Anule primeiro os pagamentos', $this->cancelamento()->motivoParaNaoCancelar($estados['out_parcial']));
        $this->assertStringContainsString('Anule primeiro os pagamentos', $this->cancelamento()->motivoParaNaoCancelar($estados['nov_paga']));
        $this->assertSame('A propina já está cancelada.', $this->cancelamento()->motivoParaNaoCancelar($estados['dez_cancelada']));
        $this->assertSame('Uma propina anulada não pode ser cancelada.', $this->cancelamento()->motivoParaNaoCancelar($estados['jan_anulada']));
    }

    public function test_com_historico_de_pagamentos_ja_nao_se_cancela_anula_se(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);
        $this->historicoPagamentoFalso()->marcar($propina);

        $this->assertStringContainsString('anula-se', $this->cancelamento()->motivoParaNaoCancelar($propina));
    }

    public function test_com_contribuicoes_activas_nao_se_cancela(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);
        $this->fontePagamentoFalsa()->definir($propina, [[1_000_000, '2026-09-05']]);

        $this->assertStringContainsString('pagamentos activos', $this->cancelamento()->motivoParaNaoCancelar($propina));
    }

    public function test_cancelar_grava_estado_motivo_autor_e_instante(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano, 3);
        $admin = $this->adminEscola();

        DB::transaction(fn () => $this->cancelamento()->cancelar($propina, '  Isenção decidida pela direcção  ', $admin->id));

        $propina->refresh();
        $this->assertSame(EstadoCobranca::CANCELADA, $propina->estado);
        $this->assertSame('Cancelada', $propina->estado_descricao);
        $this->assertSame('Isenção decidida pela direcção', $propina->motivo_cancelamento);
        $this->assertSame($admin->id, (int) $propina->cancelado_por);
        // D16: instante da escola guardado na zona da aplicação (como created_at).
        $this->assertSame('2026-10-16 10:00:00', $propina->cancelado_em->setTimezone(config('app.timezone'))->toDateTimeString());
        $this->assertSame(0, $propina->valor_pago->unidadesMenores());
        $this->assertPropinasCoerentes();
    }

    public function test_cancelar_sem_utilizador_deixa_o_autor_nulo(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);

        DB::transaction(fn () => $this->cancelamento()->cancelar($propina, 'Matrícula cancelada', null));

        $this->assertNull($propina->refresh()->cancelado_por);
        $this->assertSame(EstadoCobranca::CANCELADA, $propina->estado);
    }

    public function test_cancelar_fora_de_transaccao_lanca(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);

        // Sai da transacção do RefreshDatabase (padrão de RecalcularPropinaTest): sem isto o nível é 1 e a guarda
        // nunca dispararia. O serviço tem de lançar antes de ler a base.
        DB::rollBack();
        $this->assertSame(0, DB::transactionLevel());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('transacção');

        $this->cancelamento()->cancelar($propina, 'Motivo válido', null);
    }

    public function test_cancelar_recusa_motivo_curto_ou_longo(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);

        foreach (['   ab  ', str_repeat('x', 501)] as $motivo) {
            try {
                DB::transaction(fn () => $this->cancelamento()->cancelar($propina, $motivo, null));
                $this->fail('Devia recusar o motivo.');
            } catch (InvalidArgumentException) {
                $this->assertSame(EstadoCobranca::EM_ABERTO, $propina->refresh()->estado);
            }
        }
    }

    public function test_cancelar_recusa_uma_propina_nao_cancelavel_mesmo_que_o_chamador_nao_verifique(): void
    {
        $estados = $this->cenarioDeEstados();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Anule primeiro os pagamentos');

        DB::transaction(fn () => $this->cancelamento()->cancelar($estados['out_parcial'], 'Motivo válido', null));
    }
}
