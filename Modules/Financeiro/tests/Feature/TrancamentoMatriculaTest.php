<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\AuditoriaTenant;
use Modules\Financeiro\Actions\AtualizarRegraCobrancaAction;
use Modules\Financeiro\DTO\RegraCobrancaDTO;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Services\AplicarMultasService;
use Modules\Financeiro\Services\GerarPropinasService;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComMultasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Matricula\Actions\AlterarEstadoMatriculaAction;
use Modules\Matricula\Actions\ReactivarMatriculaAction;
use Modules\Matricula\Actions\TrancarMatriculaAction;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Matricula\Models\MatriculaHistorico;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class TrancamentoMatriculaTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComMultasFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    private const HOJE = '2026-10-16';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
        Carbon::setTestNow(CarbonImmutable::parse(self::HOJE . ' 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function trancar(Matricula $matricula, bool $confirmar = false, string $data = self::HOJE): Matricula
    {
        return app(TrancarMatriculaAction::class)->executar($matricula, $data, 'Mudança de cidade', $confirmar, $this->adminEscola()->id);
    }

    private function recusa(string $campo, callable $operacao): void
    {
        try {
            $operacao();
            $this->fail("Devia recusar em «{$campo}».");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($campo, $e->errors());
        }
    }

    public function test_com_divida_e_exigencia_activa_nao_tranca(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->propina($matricula, $plano, 1); // Set/2026: período já iniciado, em aberto

        $this->recusa('estado', fn () => $this->trancar($matricula));

        $this->assertSame(EstadoMatriculaEnum::ACTIVA, $matricula->fresh()->estado);
    }

    public function test_sem_divida_tranca_cancela_futuras_sem_pagamento_e_regista_historico(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $futura = $this->propina($matricula, $plano, 5); // Jan/2027

        $trancada = $this->trancar($matricula);

        $this->assertSame(EstadoMatriculaEnum::TRANCADA, $trancada->estado);
        $this->assertSame('Trancada', $trancada->estado_descricao);
        $this->assertSame(EstadoCobranca::CANCELADA, $futura->fresh()->estado);
        $historico = MatriculaHistorico::query()->where('matricula_id', $matricula->id)->latest('id')->first();
        $this->assertSame(EstadoMatriculaEnum::TRANCADA, $historico->estado_novo);
        $this->assertSame(self::HOJE, $historico->data_efeito->toDateString());
        $this->assertSame('Mudança de cidade', $historico->motivo);
    }

    public function test_exigencia_desactivada_pede_confirmacao_e_preserva_a_divida(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $divida = $this->propina($matricula, $plano, 1);
        RegraCobranca::doTenant()->update(['exigir_regularizacao_trancamento' => false]);

        $this->recusa('confirmar_com_dividas', fn () => $this->trancar($matricula));

        $this->trancar($matricula, confirmar: true);

        $this->assertSame(EstadoMatriculaEnum::TRANCADA, $matricula->fresh()->estado);
        $this->assertSame(EstadoCobranca::EM_ABERTO, $divida->fresh()->estado);
        $this->assertTrue(MatriculaHistorico::query()->where('matricula_id', $matricula->id)->latest('id')->first()->detalhe['confirmou_com_dividas']);
    }

    public function test_data_futura_motivo_curto_e_matricula_nao_activa_sao_recusados(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();

        $this->recusa('data_efeito', fn () => $this->trancar($matricula, false, '2026-10-17'));
        $this->recusa('data_efeito', fn () => $this->trancar($matricula, false, '2026-08-01'));
        $this->recusa('motivo', fn () => app(TrancarMatriculaAction::class)->executar($matricula, self::HOJE, 'abc'));

        $this->trancar($matricula);
        $this->recusa('estado', fn () => $this->trancar($matricula->fresh()));
    }

    public function test_a_transicao_generica_nao_tranca_nem_reactiva(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $acao = app(AlterarEstadoMatriculaAction::class);

        $this->recusa('estado', fn () => $acao->executar($matricula, EstadoMatriculaEnum::TRANCADA, $this->adminEscola()->id));

        $this->trancar($matricula);
        $this->recusa('estado', fn () => $acao->executar($matricula->fresh(), EstadoMatriculaEnum::ACTIVA, $this->adminEscola()->id));
        $this->assertSame(EstadoMatriculaEnum::CANCELADA, $acao->executar($matricula->fresh(), EstadoMatriculaEnum::CANCELADA, $this->adminEscola()->id, self::HOJE)->estado);
    }

    public function test_reactivar_valida_data_motivo_e_volta_a_activa(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $this->trancar($matricula);
        $reactivar = app(ReactivarMatriculaAction::class);

        $this->recusa('data_efeito', fn () => $reactivar->executar($matricula->fresh(), '2026-10-01', 'Voltou à cidade'));
        $this->recusa('motivo', fn () => $reactivar->executar($matricula->fresh(), self::HOJE, 'x'));

        $this->assertSame(EstadoMatriculaEnum::ACTIVA, $reactivar->executar($matricula->fresh(), self::HOJE, 'Voltou à cidade', $this->adminEscola()->id)->estado);
        $this->recusa('estado', fn () => $reactivar->executar($matricula->fresh(), self::HOJE, 'Voltou à cidade'));
    }

    public function test_multas_ficam_congeladas_enquanto_trancada(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano, 1);
        $this->multaActiva([[1, 1, 500]]);
        RegraCobranca::doTenant()->update(['exigir_regularizacao_trancamento' => false]);
        $this->trancar($matricula, confirmar: true);

        $this->assertNotNull($propina->fresh()->multa, 'A multa já devida foi aplicada antes de trancar.');
        $outra = $this->propina($matricula, $plano, 2);

        $resultado = app(AplicarMultasService::class)->executarParaMatricula($matricula->id);

        $this->assertSame(0, $resultado['criadas']);
        $this->assertNull($outra->fresh()->multa, 'Trancada: nenhuma multa nova.');
    }

    public function test_periodos_dentro_do_trancamento_nao_sao_gerados_ao_reactivar(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->trancar($matricula, false, self::HOJE);
        MatriculaHistorico::query()->where('matricula_id', $matricula->id)->update(['data_efeito' => '2026-09-01']);
        Carbon::setTestNow(CarbonImmutable::parse('2026-12-10 10:00:00', 'UTC'));
        app(ReactivarMatriculaAction::class)->executar($matricula->fresh(), '2026-12-01', 'Voltou à cidade', $this->adminEscola()->id);

        $criadas = Propina::query()->where('matricula_id', $matricula->id)->pluck('periodo_inicio')->map->toDateString()->all();

        $this->assertNotContains('2026-10-01', $criadas);
        $this->assertNotContains('2026-11-01', $criadas);
        $this->assertSame(10, count($plano->periodos()));
    }

    public function test_a_alteracao_das_regras_fica_na_auditoria_do_tenant(): void
    {
        $regra = RegraCobranca::doTenant();
        $dto = new RegraCobrancaDTO(
            $regra->dia_vencimento, $regra->dias_tolerancia, $regra->permite_pagamento_parcial, $regra->permite_pagamento_antecipado,
            $regra->gerar_automaticamente, $regra->permite_negociacao, $regra->desconto_maximo_negociacao, null, false, null,
        );

        app(AtualizarRegraCobrancaAction::class)->executar($dto);
        app(AtualizarRegraCobrancaAction::class)->executar($dto); // sem diferenças: não regista de novo

        $linhas = AuditoriaTenant::query()->where('accao', 'regra_cobranca.actualizada')->get();
        $this->assertCount(1, $linhas);
        $this->assertSame(['exigir_regularizacao_trancamento' => true], $linhas[0]->detalhe['antes']);
        $this->assertSame(['exigir_regularizacao_trancamento' => false], $linhas[0]->detalhe['depois']);
    }
}
