<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Financeiro\Actions\AlterarPrecoPlanoAction;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Models\AlteracaoPrecoPlano;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaAjuste;
use Modules\Financeiro\Tests\Concerns\ComAlteracaoPreco;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Spec §14 "Atomicidade de ponta a ponta": uma falha em qualquer etapa reverte tudo. (Em PostgreSQL, a reversão
 * depois de um erro a meio prova-se na Task 15.)
 */
class AlterarPrecoPlanoFalhasTest extends TestCase
{
    use ComAlteracaoPreco;
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

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

    /** Lança na n-ésima actualização de uma propina que cumpra a condição. */
    private static function naPropina(int $n, Closure $condicao, string $etapa): void
    {
        $vezes = 0;
        Propina::updating(function (Propina $propina) use ($n, $condicao, $etapa, &$vezes) {
            if ($condicao($propina) && ++$vezes === $n) {
                throw new RuntimeException("Falha injectada: {$etapa}");
            }
        });
    }

    public static function etapas(): array
    {
        $aplicar = ModoAlteracaoPreco::APLICAR_GERADAS;
        $manter = ModoAlteracaoPreco::MANTER_GERADAS;

        return [
            'encurtar o plano antigo' => ['2027-02', $aplicar, fn () => PlanoPropina::updating(fn (PlanoPropina $p) => $p->isDirty('mes_fim') ? throw new RuntimeException('Falha injectada: encurtar') : null)],
            'criar o plano novo' => ['2027-02', $aplicar, fn () => PlanoPropina::creating(fn () => throw new RuntimeException('Falha injectada: plano novo'))],
            'mudar o valor in loco' => ['2026-09', $aplicar, fn () => PlanoPropina::updating(fn (PlanoPropina $p) => $p->isDirty('valor') ? throw new RuntimeException('Falha injectada: valor in loco') : null)],
            'reassociar a 2.ª propina' => ['2027-02', $aplicar, fn () => self::naPropina(2, fn (Propina $p) => $p->isDirty('plano_propina_id'), 'reassociar')],
            'reajustar a 2.ª propina' => ['2027-02', $aplicar, fn () => self::naPropina(2, fn (Propina $p) => $p->isDirty('valor'), 'reajustar')],
            'registar a alteração' => ['2027-02', $aplicar, fn () => AlteracaoPrecoPlano::creating(fn () => throw new RuntimeException('Falha injectada: alteração'))],
            'registar o 3.º ajuste' => ['2027-02', $aplicar, function () {
                $vezes = 0;
                PropinaAjuste::creating(function () use (&$vezes) {
                    if (++$vezes === 3) {
                        throw new RuntimeException('Falha injectada: ajuste');
                    }
                });
            }],
            'registar a alteração em MANTER' => ['2027-02', $manter, fn () => AlteracaoPrecoPlano::creating(fn () => throw new RuntimeException('Falha injectada: alteração MANTER'))],
        ];
    }

    #[DataProvider('etapas')]
    public function test_uma_falha_em_qualquer_etapa_reverte_tudo(string $mesEfeito, ModoAlteracaoPreco $modo, Closure $injectar): void
    {
        $e = $this->cenarioDeEstados();
        $fonte = $this->fontePagamentoFalsa();
        $fonte->definir($e['out_parcial'], [[1_000_000, '2026-10-05']]);
        $fonte->definir($e['mar_parcial'], [[2_000_000, '2026-10-05']]);
        $plano = $e['set_aberta']->plano()->firstOrFail();
        $impressao = $this->previa($plano, $mesEfeito, 3_000_000, $modo)->impressaoDigital;
        $antes = $this->fotografia();
        $injectar();

        try {
            app(AlterarPrecoPlanoAction::class)->executar($plano->fresh(), $this->pedidoAlteracao($mesEfeito, 3_000_000, $modo, 'Motivo', $impressao, true));
            $this->fail('A falha injectada devia chegar ao chamador.');
        } catch (RuntimeException $excepcao) {
            $this->assertStringStartsWith('Falha injectada', $excepcao->getMessage());
        }

        $this->assertSame($antes, $this->fotografia(), 'Plano, alvos, propinas e registos exactamente como antes.');
        $this->assertPropinasCoerentes();
    }
}
