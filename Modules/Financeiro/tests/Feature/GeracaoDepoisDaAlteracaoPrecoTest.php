<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
use Modules\Financeiro\DTO\ResultadoGeracaoPropinasDTO;
use Modules\Financeiro\Enums\AmbitoGeracaoPropinas;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\GerarPropinasService;
use Modules\Financeiro\Tests\Concerns\ComAlteracaoPreco;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\Turma;
use Tests\TestCase;

class GeracaoDepoisDaAlteracaoPrecoTest extends TestCase
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

    private function gerar(Turma $turma): ResultadoGeracaoPropinasDTO
    {
        return app(GerarPropinasService::class)->gerar(new PedidoGeracaoPropinasDTO(
            ano_lectivo_id: (int) $turma->ano_lectivo_id,
            ambito: AmbitoGeracaoPropinas::TURMA,
            origem: OrigemGeracaoPropina::MANUAL,
            alvo_id: (int) $turma->id,
        ));
    }

    /** @return list<array{0: string, 1: int, 2: int, 3: int}> [periodo_inicio, plano, ordem, valor] por período */
    private function propinasDe(Matricula $matricula): array
    {
        return Propina::query()->where('matricula_id', $matricula->id)->orderBy('periodo_inicio')->get()
            ->map(fn (Propina $p) => [$p->periodo_inicio->toDateString(), (int) $p->plano_propina_id, (int) $p->ordem, $p->valor->unidadesMenores()])
            ->all();
    }

    private function planoNovo(): PlanoPropina
    {
        return PlanoPropina::query()->where('nome', 'Propina Mensal (desde 02/2027)')->firstOrFail();
    }

    public function test_depois_de_manter_as_ja_geradas_ficam_e_as_novas_matriculas_nascem_ao_valor_novo(): void
    {
        ['turma' => $turma, 'plano' => $plano, 'matricula' => $antiga] = $this->cenarioPropinas();
        $this->assertSame(10, $this->gerar($turma)->totais()['criadas']);
        $antes = $this->propinasDe($antiga);

        $this->alterarPreco($plano, '2027-02', 3_000_000, ModoAlteracaoPreco::MANTER_GERADAS);
        $nova = $this->matriculaActiva($turma);

        $this->assertSame(10, $this->gerar($turma)->totais()['criadas'], 'Só a nova: a antiga já está coberta (de qualquer plano).');
        $this->assertSame($antes, $this->propinasDe($antiga));

        $novo = $this->planoNovo();
        $daNova = $this->propinasDe($nova);
        $this->assertSame([$plano->id, 5, 2_500_000], array_slice($daNova[4], 1));      // Jan/2027, plano antigo
        $this->assertSame(['2027-02-01', $novo->id, 1, 3_000_000], $daNova[5]);         // Fev/2027, plano novo
        $this->assertSame(['2027-06-01', $novo->id, 5, 3_000_000], $daNova[9]);

        $this->assertSame(0, $this->gerar($turma)->totais()['criadas']);
        $this->assertPropinasCoerentes();
    }

    public function test_depois_de_aplicar_a_geracao_nao_recria_os_meses_reassociados(): void
    {
        ['turma' => $turma, 'plano' => $plano, 'matricula' => $antiga] = $this->cenarioPropinas();
        $this->gerar($turma);

        $this->alterarPreco($plano, '2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS);
        $novo = $this->planoNovo();

        $this->assertSame(0, $this->gerar($turma)->totais()['criadas']);
        $this->assertSame(['2027-02-01', $novo->id, 1, 3_000_000], $this->propinasDe($antiga)[5]);

        $nova = $this->matriculaActiva($turma);
        $this->assertSame(10, $this->gerar($turma)->totais()['criadas']);
        $this->assertSame($this->propinasDe($antiga), $this->propinasDe($nova), 'Antiga (reassociada) e nova (gerada) ficam iguais período a período.');
        $this->assertPropinasCoerentes();
    }

    public function test_depois_de_in_loco_as_novas_matriculas_nascem_ao_valor_novo_no_mesmo_plano(): void
    {
        ['turma' => $turma, 'plano' => $plano] = $this->cenarioPropinas();
        $this->gerar($turma);

        $this->alterarPreco($plano, '2026-09', 3_000_000, ModoAlteracaoPreco::MANTER_GERADAS);
        $nova = $this->matriculaActiva($turma);
        $this->gerar($turma);

        $this->assertSame(array_fill(0, 10, 3_000_000), array_column($this->propinasDe($nova), 3));
        $this->assertSame([$plano->id], array_values(array_unique(array_column($this->propinasDe($nova), 1))));
    }
}
