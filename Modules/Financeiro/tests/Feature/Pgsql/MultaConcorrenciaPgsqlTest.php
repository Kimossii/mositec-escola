<?php

namespace Modules\Financeiro\Tests\Feature\Pgsql;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Modules\Financeiro\Enums\TipoMulta;
use Modules\Financeiro\Services\AplicarMultasService;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComMultasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use PHPUnit\Framework\Attributes\Group;
use Tests\PgsqlConcorrenciaTestCase;

/**
 * Multas (F5) com sessões PostgreSQL reais: duas execuções simultâneas do AplicarMultasService não duplicam multa nem
 * revisões (insertOrIgnore + UPDATE condicional), e o filtro das PAGA por data (capital_liquidado_em >= L1) funciona
 * com a sintaxe de datas do PostgreSQL. SQLite ignora FOR UPDATE.
 */
#[Group('pgsql')]
class MultaConcorrenciaPgsqlTest extends PgsqlConcorrenciaTestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComMultasFinanceiro;
    use ComPagamentosFinanceiro;
    use ComProcessosConcorrentes;
    use ComPropinasFinanceiro;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return list<array<string, mixed>> respostas dos filhos lançados ao mesmo tempo */
    private function emSimultaneo(array $operacoes): array
    {
        $partida = microtime(true) + 2.5;
        $filhos = array_map(fn (array $op) => $this->lancar($op[0], $op[1], $partida), $operacoes);
        $respostas = [];
        $erro = null;

        foreach ($filhos as $i => $filho) {
            try {
                $respostas[$i] = $this->esperar($filho);
            } catch (\Throwable $e) {
                $erro ??= $e;
            }
        }

        if ($erro !== null) {
            throw $erro;
        }

        return $respostas;
    }

    public function test_duas_execucoes_simultaneas_nao_duplicam_multa_nem_revisao(): void
    {
        $this->multaActiva([[1, TipoMulta::PERCENTAGEM, 250], [30, TipoMulta::PERCENTAGEM, 500]]);
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->propina($matricula, $plano);

        $respostas = $this->emSimultaneo([
            ['aplicar-multas', []],
            ['aplicar-multas', []],
        ]);

        $this->assertSame([true, true], array_column($respostas, 'ok'), json_encode($respostas));
        $this->assertSame(1, (int) $this->outraLigacao()->selectOne('select count(*) as n from propina_multas')->n, 'Uma só multa.');
        $this->assertSame(2, (int) $this->outraLigacao()->selectOne('select count(*) as n from propina_multa_revisoes')->n, 'Revisões dos 2 escalões, sem duplicar.');
    }

    public function test_o_comando_filtra_paga_por_data_em_pgsql(): void
    {
        $this->regras();
        ['turma' => $turma, 'plano' => $plano, 'matricula' => $matriculaA] = $this->cenarioPropinas();
        $emL1 = $this->propina($matriculaA, $plano);
        $matriculaB = $this->matriculaActiva($turma);
        $antesDeL1 = $this->propina($matriculaB, $plano);

        $this->pagar($matriculaA, 2_500_000, [[$emL1, 2_500_000]], 0, '2026-09-16'); // exactamente em L1
        $this->pagar($matriculaB, 2_500_000, [[$antesDeL1, 2_500_000]], 0, '2026-09-15'); // um dia antes de L1

        $this->multaActiva([[1, TipoMulta::PERCENTAGEM, 500]]); // L1 = 16/09
        app(AplicarMultasService::class)->executar();

        $this->assertSame(1, (int) $this->outraLigacao()->selectOne('select count(*) as n from propina_multas')->n);
        $multa = $this->outraLigacao()->selectOne('select propina_id from propina_multas');
        $this->assertSame((int) $emL1->id, (int) $multa->propina_id, 'Só a PAGA liquidada exactamente em L1 recebe multa.');
    }
}
