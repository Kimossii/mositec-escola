<?php

namespace Modules\Horario\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Horario\Enums\DiaSemana;
use Modules\Horario\Models\GrelhaEntrada;
use Modules\Horario\Tests\Support\CenarioDeHorario;
use Tests\TestCase;

class GrelhaEntradaModelTest extends TestCase
{
    use CenarioDeHorario;
    use RefreshDatabase;

    public function test_guarda_descricao_do_dia(): void
    {
        $entrada = $this->criarEntrada($this->cenarioDeHorario(), ['dia_semana' => 3]);

        $this->assertSame(DiaSemana::QUARTA, $entrada->dia_semana);
        $this->assertSame('Quarta-feira', $entrada->fresh()->dia_semana_descricao);
    }

    public function test_dia_semana_a_partir_de_uma_data(): void
    {
        $this->assertSame(DiaSemana::SEGUNDA, DiaSemana::fromData(Carbon::parse('2026-06-15')));
        $this->assertSame(DiaSemana::SABADO, DiaSemana::fromData(Carbon::parse('2026-06-20')));
        $this->assertNull(DiaSemana::fromData(Carbon::parse('2026-06-21')));
    }

    public function test_scope_em_vigor_inclui_os_dois_extremos(): void
    {
        $c = $this->cenarioDeHorario();
        $fechada = $this->criarEntrada($c, ['vigencia_inicio' => '2026-02-01', 'vigencia_fim' => '2026-03-31']);
        $aberta = $this->criarEntrada($c, ['dia_semana' => 2, 'vigencia_inicio' => '2026-04-01']);

        $ids = fn (string $d) => GrelhaEntrada::emVigor(Carbon::parse($d))->orderBy('id')->pluck('id')->all();

        $this->assertSame([$fechada->id], $ids('2026-02-01'));
        $this->assertSame([$fechada->id], $ids('2026-03-31'));
        $this->assertSame([$aberta->id], $ids('2026-04-01'));
        $this->assertSame([], $ids('2026-01-31'));
    }

    public function test_scope_sobrepoe_trata_fim_nulo_como_aberto(): void
    {
        $c = $this->cenarioDeHorario();
        $e = $this->criarEntrada($c, ['vigencia_inicio' => '2026-02-01', 'vigencia_fim' => '2026-03-31']);

        $this->assertTrue(GrelhaEntrada::sobrepoe('2026-03-31', null)->whereKey($e->id)->exists());
        $this->assertTrue(GrelhaEntrada::sobrepoe('2026-01-01', '2026-02-01')->whereKey($e->id)->exists());
        $this->assertFalse(GrelhaEntrada::sobrepoe('2026-04-01', null)->whereKey($e->id)->exists());
        $this->assertFalse(GrelhaEntrada::sobrepoe('2026-01-01', '2026-01-31')->whereKey($e->id)->exists());
    }

    public function test_fk_composta_recusa_turma_de_outro_tenant(): void
    {
        $c = $this->cenarioDeHorario();
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $turmaB = $this->noTenant($outro, fn () => $this->cenarioDeDocencia()['turma']);

        $this->expectException(QueryException::class);

        $this->criarEntrada($c, ['turma_id' => $turmaB->id]);
    }

    public function test_isolada_por_tenant(): void
    {
        $entradaA = $this->criarEntrada($this->cenarioDeHorario());
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $entradaB = $this->noTenant($outro, fn () => $this->criarEntrada($this->cenarioDeHorario()));

        $this->assertSame([$entradaA->id], GrelhaEntrada::pluck('id')->all());
        $this->assertNull(GrelhaEntrada::find($entradaB->id));
    }
}
