<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Actions\CriarAcordoAction;
use Modules\Financeiro\Actions\EncerrarAcordoAction;
use Modules\Financeiro\Enums\EstadoAcordo;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Services\AplicarMultasService;
use Modules\Financeiro\Support\ProgressoDoAcordo;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComMultasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class AcordosTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComMultasFinanceiro;
    use ComPagamentosFinanceiro;
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

    private function cenario(): array
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $set = $this->propina($matricula, $plano, 1);
        $out = $this->propina($matricula, $plano, 2);
        $this->multa($set, 400_000);
        RegraCobranca::doTenant()->update(['permite_negociacao' => true, 'desconto_maximo_negociacao' => 50]);

        return [$matricula, $set, $out];
    }

    private function acordo($matricula, array $ids, int $desconto = 50, int $prestacoes = 4)
    {
        return app(CriarAcordoAction::class)->executar($matricula, $ids, $desconto, $prestacoes, '2026-11-01', 'Pais sem rendimento este mês', $this->adminEscola()->id);
    }

    public function test_cria_acordo_com_desconto_nas_multas_e_prestacoes_que_somam_o_total(): void
    {
        [$matricula, $set, $out] = $this->cenario();

        $acordo = $this->acordo($matricula, [$set->id, $out->id], 50, 3);

        $this->assertSame(5_400_000, $acordo->divida_inicial->unidadesMenores());
        $this->assertSame(200_000, $acordo->desconto->unidadesMenores());
        $this->assertSame(5_200_000, $acordo->total->unidadesMenores());
        $this->assertSame(5_200_000, $acordo->prestacoes->sum(fn ($p) => $p->valor->unidadesMenores()));
        $this->assertSame(['2026-11-01', '2026-12-01', '2027-01-01'], $acordo->prestacoes->map(fn ($p) => $p->data_vencimento->toDateString())->all());
        $this->assertSame(200_000, $set->fresh('multa')->multaPendente());
    }

    public function test_recusa_sem_permissao_de_negociacao_desconto_acima_do_maximo_e_acordo_duplicado(): void
    {
        [$matricula, $set, $out] = $this->cenario();

        foreach ([
            fn () => $this->acordo($matricula, [$set->id], 60),
            function () use ($matricula, $set) {
                RegraCobranca::doTenant()->update(['permite_negociacao' => false]);
                $this->acordo($matricula, [$set->id]);
            },
        ] as $tentativa) {
            try {
                $tentativa();
                $this->fail('Devia recusar.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }

        RegraCobranca::doTenant()->update(['permite_negociacao' => true, 'desconto_maximo_negociacao' => 50]);
        $this->acordo($matricula, [$set->id]);

        try {
            $this->acordo($matricula, [$out->id]);
            $this->fail('Um só acordo activo por matrícula.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('acordo', $e->errors());
        }
    }

    public function test_pagamento_parcial_e_permitido_durante_o_acordo_e_o_progresso_acompanha(): void
    {
        [$matricula, $set, $out] = $this->cenario(); // a escola NÃO permite pagamento parcial
        $acordo = $this->acordo($matricula, [$set->id, $out->id], 0, 2);

        $this->pagar($matricula, 1_000_000); // parcial: só passa por causa do acordo

        $p = app(ProgressoDoAcordo::class)->de($acordo->fresh());
        $this->assertSame(1_000_000, $p['pago']);
        $this->assertSame('pendente', $p['prestacoes'][0]['estado']);
        $this->assertFalse($p['cumprido']);

        $this->pagar($matricula, 1_700_000);
        $this->assertSame('paga', app(ProgressoDoAcordo::class)->de($acordo->fresh())['prestacoes'][0]['estado']);
    }

    public function test_multas_congeladas_no_acordo_e_voltam_ao_quebrar(): void
    {
        [$matricula, $set, $out] = $this->cenario();
        $this->multaActiva([[1, 1, 1000]]);
        $acordo = $this->acordo($matricula, [$out->id], 0, 2);

        app(AplicarMultasService::class)->executarParaMatricula($matricula->id);
        $this->assertNull($out->fresh()->multa, 'Congelada durante o acordo.');

        app(EncerrarAcordoAction::class)->executar($acordo, EstadoAcordo::QUEBRADO, 'O aluno falhou a 1.ª prestação');
        app(AplicarMultasService::class)->executarParaMatricula($matricula->id);
        $this->assertNotNull($out->fresh()->multa, 'Quebrado: as multas voltam a incidir.');
    }
}
