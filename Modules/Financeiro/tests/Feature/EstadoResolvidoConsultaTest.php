<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EstadoResolvidoConsultaTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    /** @return list<int> */
    private function idsPorSql(EstadoCobranca $estado, string $dia): array
    {
        return Propina::query()->comEstadoResolvido($estado, CarbonImmutable::parse($dia))->orderBy('id')->pluck('id')->all();
    }

    #[DataProvider('dias')]
    public function test_o_filtro_sql_coincide_com_o_php_para_os_sete_estados(string $dia): void
    {
        $this->cenarioDeEstados();
        $hoje = CarbonImmutable::parse($dia);
        $porEstado = Propina::query()->orderBy('id')->get()->groupBy(fn (Propina $p) => $p->estadoResolvido($hoje)->value);
        $todos = [];

        foreach (EstadoCobranca::cases() as $estado) {
            $sql = $this->idsPorSql($estado, $dia);

            $this->assertSame(($porEstado[$estado->value] ?? collect())->pluck('id')->all(), $sql, "{$estado->label()} em {$dia}");
            array_push($todos, ...$sql);
        }

        // Partição: cada propina cai em exactamente um estado resolvido.
        sort($todos);
        $this->assertSame(Propina::query()->orderBy('id')->pluck('id')->all(), $todos, "partição em {$dia}");
    }

    public static function dias(): array
    {
        return [
            'antes do ano' => ['2026-08-31'],
            'início de Setembro' => ['2026-09-01'],
            'limite de Setembro' => ['2026-09-15'],
            'dia seguinte ao limite' => ['2026-09-16'],
            'limite de Outubro' => ['2026-10-15'],
            'depois do limite de Outubro' => ['2026-10-16'],
            'Março' => ['2027-03-20'],
            'fim do ano lectivo' => ['2027-07-01'],
        ];
    }

    public function test_matriz_explicita_a_16_de_outubro(): void
    {
        $p = $this->cenarioDeEstados();
        $esperado = [
            EstadoCobranca::EM_ATRASO->value => [$p['set_aberta']->id, $p['out_parcial']->id],
            EstadoCobranca::PAGA->value => [$p['nov_paga']->id, $p['abr_paga']->id],
            EstadoCobranca::CANCELADA->value => [$p['dez_cancelada']->id],
            EstadoCobranca::ANULADA->value => [$p['jan_anulada']->id],
            EstadoCobranca::PENDENTE->value => [$p['fev_aberta']->id, $p['mai_aberta']->id, $p['jun_aberta']->id],
            EstadoCobranca::PARCIALMENTE_PAGA->value => [$p['mar_parcial']->id],
            EstadoCobranca::EM_ABERTO->value => [],
        ];

        foreach ($esperado as $valor => $ids) {
            $this->assertSame($ids, $this->idsPorSql(EstadoCobranca::from($valor), '2026-10-16'), EstadoCobranca::from($valor)->label());
        }
    }

    public function test_fronteira_da_tolerancia_e_do_inicio_do_periodo(): void
    {
        $p = $this->cenarioDeEstados();

        // Setembro: aberta até ao limite (15), em atraso a partir de 16.
        $this->assertContains($p['set_aberta']->id, $this->idsPorSql(EstadoCobranca::EM_ABERTO, '2026-09-15'));
        $this->assertContains($p['set_aberta']->id, $this->idsPorSql(EstadoCobranca::EM_ATRASO, '2026-09-16'));
        // Outubro, parcialmente paga: parcial no próprio limite, em atraso no dia seguinte (mostra-se o pago à parte, §4).
        $this->assertContains($p['out_parcial']->id, $this->idsPorSql(EstadoCobranca::PARCIALMENTE_PAGA, '2026-10-15'));
        $this->assertContains($p['out_parcial']->id, $this->idsPorSql(EstadoCobranca::EM_ATRASO, '2026-10-16'));
        // Pendente na véspera do início, Em Aberto no próprio dia do início.
        $this->assertContains($p['set_aberta']->id, $this->idsPorSql(EstadoCobranca::PENDENTE, '2026-08-31'));
        $this->assertContains($p['set_aberta']->id, $this->idsPorSql(EstadoCobranca::EM_ABERTO, '2026-09-01'));
        // Paga continua Paga muito depois do limite; parcial futura é parcial, nunca Pendente.
        $this->assertContains($p['nov_paga']->id, $this->idsPorSql(EstadoCobranca::PAGA, '2027-07-01'));
        $this->assertContains($p['mar_parcial']->id, $this->idsPorSql(EstadoCobranca::PARCIALMENTE_PAGA, '2026-09-01'));
    }

    public function test_o_filtro_combina_com_outras_condicoes_e_nao_ve_outro_tenant(): void
    {
        $p = $this->cenarioDeEstados();
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, fn () => $this->cenarioDeEstados());

        $ids = Propina::query()
            ->where('matricula_id', $p['set_aberta']->matricula_id)
            ->comEstadoResolvido(EstadoCobranca::EM_ATRASO, CarbonImmutable::parse('2026-10-16'))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame([$p['set_aberta']->id, $p['out_parcial']->id], $ids);
    }
}
