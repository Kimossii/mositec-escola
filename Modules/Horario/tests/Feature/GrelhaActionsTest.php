<?php

namespace Modules\Horario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Horario\Actions\AlterarEntradaAction;
use Modules\Horario\Actions\CriarEntradaAction;
use Modules\Horario\Actions\EliminarEntradaAction;
use Modules\Horario\Actions\EncerrarEntradaAction;
use Modules\Horario\DTO\GrelhaEntradaDTO;
use Modules\Horario\Models\GrelhaEntrada;
use Modules\Horario\Tests\Support\CenarioDeHorario;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class GrelhaActionsTest extends TestCase
{
    use CenarioDeHorario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-02 10:00:00');
    }

    private function dto(array $c, array $o = []): GrelhaEntradaDTO
    {
        return new GrelhaEntradaDTO(
            turma_id: $o['turma_id'] ?? $c['turma']->id,
            dia_semana: $o['dia_semana'] ?? 1,
            horario_id: $o['horario_id'] ?? $c['tempo1']->id,
            disciplina_id: $o['disciplina_id'] ?? $c['disciplina']->id,
            sala_id: $o['sala_id'] ?? null,
            vigencia_inicio: $o['vigencia_inicio'] ?? '2026-03-02',
            vigencia_fim: $o['vigencia_fim'] ?? null,
        );
    }

    public function test_criar_grava_ano_da_turma_e_autoria(): void
    {
        $c = $this->cenarioDeHorario();
        $utilizador = User::create(['name' => 'Sec', 'email' => 'sec@example.com', 'password' => 'x']);
        $this->actingAs($utilizador);

        $entrada = app(CriarEntradaAction::class)->executar($this->dto($c, ['sala_id' => $c['sala']->id]));

        $this->assertSame($c['ano']->id, (int) $entrada->ano_lectivo_id);
        $this->assertSame($utilizador->id, (int) $entrada->criado_por);
        $this->assertSame('Segunda-feira', $entrada->dia_semana_descricao);
    }

    public function test_alterar_fecha_a_antiga_na_vespera_e_cria_nova(): void
    {
        $c = $this->cenarioDeHorario();
        $antiga = $this->criarEntrada($c, ['vigencia_inicio' => '2026-02-01']);

        $nova = app(AlterarEntradaAction::class)->executar($antiga, $this->dto($c, [
            'disciplina_id' => $c['outraDisciplina']->id, 'vigencia_inicio' => '2026-03-10',
        ]));

        $this->assertSame('2026-03-09', $antiga->fresh()->vigencia_fim->toDateString());
        $this->assertSame($c['outraDisciplina']->id, (int) $nova->disciplina_id);
        $this->assertNull($nova->vigencia_fim);
        $this->assertSame(2, GrelhaEntrada::count());
    }

    public function test_alterar_com_inicio_nao_posterior_e_recusado(): void
    {
        $c = $this->cenarioDeHorario();
        $antiga = $this->criarEntrada($c, ['vigencia_inicio' => '2026-02-01']);

        $this->expectException(ValidationException::class);

        app(AlterarEntradaAction::class)->executar($antiga, $this->dto($c, ['vigencia_inicio' => '2026-02-01']));
    }

    public function test_alterar_nao_perde_historico(): void
    {
        $c = $this->cenarioDeHorario();
        $antiga = $this->criarEntrada($c, ['vigencia_inicio' => '2026-02-01']);

        app(AlterarEntradaAction::class)->executar($antiga, $this->dto($c, ['vigencia_inicio' => '2026-03-10', 'dia_semana' => 2]));

        $this->assertNotNull(GrelhaEntrada::find($antiga->id));
        $this->assertSame(1, GrelhaEntrada::emVigor(Carbon::parse('2026-03-05'))->count());
        $this->assertSame(1, GrelhaEntrada::emVigor(Carbon::parse('2026-03-12'))->count());
    }

    public function test_alterar_com_conflito_nao_fecha_a_antiga(): void
    {
        $c = $this->cenarioDeHorario();
        $antiga = $this->criarEntrada($c, ['vigencia_inicio' => '2026-02-01']);
        $this->criarEntrada($c, ['dia_semana' => 2, 'vigencia_inicio' => '2026-02-01']);

        try {
            app(AlterarEntradaAction::class)->executar($antiga, $this->dto($c, ['dia_semana' => 2, 'vigencia_inicio' => '2026-03-10']));
            $this->fail('Esperava conflito de turma.');
        } catch (ValidationException) {
            $this->assertNull($antiga->fresh()->vigencia_fim);
            $this->assertSame(2, GrelhaEntrada::count());
        }
    }

    public function test_encerrar_define_fim(): void
    {
        $c = $this->cenarioDeHorario();
        $entrada = $this->criarEntrada($c, ['vigencia_inicio' => '2026-02-01']);

        $r = app(EncerrarEntradaAction::class)->executar($entrada, '2026-06-30');

        $this->assertSame('2026-06-30', $r->vigencia_fim->toDateString());
    }

    public function test_encerrar_recusa_fim_no_passado_ou_antes_do_inicio(): void
    {
        $c = $this->cenarioDeHorario();
        $entrada = $this->criarEntrada($c, ['vigencia_inicio' => '2026-02-01']);

        foreach (['2026-03-01', '2026-01-31'] as $data) {
            try {
                app(EncerrarEntradaAction::class)->executar($entrada, $data);
                $this->fail("Esperava recusa para {$data}.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('vigencia_fim', $e->errors());
            }
        }
    }

    public function test_eliminar_so_antes_de_comecar(): void
    {
        $c = $this->cenarioDeHorario();
        $futura = $this->criarEntrada($c, ['vigencia_inicio' => '2026-04-01']);
        $emCurso = $this->criarEntrada($c, ['dia_semana' => 2, 'vigencia_inicio' => '2026-02-01']);

        app(EliminarEntradaAction::class)->executar($futura);
        $this->assertNull(GrelhaEntrada::find($futura->id));

        $this->expectException(ValidationException::class);
        app(EliminarEntradaAction::class)->executar($emCurso);
    }

    public function test_alterar_recusa_entrada_que_ja_tem_fim(): void
    {
        $c = $this->cenarioDeHorario();
        $comFim = $this->criarEntrada($c, ['vigencia_inicio' => '2026-02-01', 'vigencia_fim' => '2026-04-30']);

        try {
            app(AlterarEntradaAction::class)->executar($comFim, $this->dto($c, ['vigencia_inicio' => '2026-06-01']));
            $this->fail('Esperava recusa.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('entrada', $e->errors());
            $this->assertSame('2026-04-30', $comFim->fresh()->vigencia_fim->toDateString());
            $this->assertSame(1, GrelhaEntrada::count());
        }
    }

    public function test_alterar_recusa_inicio_no_passado(): void
    {
        $c = $this->cenarioDeHorario();
        $entrada = $this->criarEntrada($c, ['vigencia_inicio' => '2026-01-05']);

        try {
            app(AlterarEntradaAction::class)->executar($entrada, $this->dto($c, ['vigencia_inicio' => '2026-02-01']));
            $this->fail('Esperava recusa.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('vigencia_inicio', $e->errors());
            $this->assertNull($entrada->fresh()->vigencia_fim);
        }
    }

    public function test_encerrar_so_encurta_nunca_alarga(): void
    {
        $c = $this->cenarioDeHorario();
        $entrada = $this->criarEntrada($c, ['vigencia_inicio' => '2026-02-01', 'vigencia_fim' => '2026-04-30']);

        try {
            app(EncerrarEntradaAction::class)->executar($entrada, '2026-06-30');
            $this->fail('Esperava recusa.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('vigencia_fim', $e->errors());
            $this->assertSame('2026-04-30', $entrada->fresh()->vigencia_fim->toDateString());
        }

        $this->assertSame('2026-04-15', app(EncerrarEntradaAction::class)->executar($entrada, '2026-04-15')->vigencia_fim->toDateString());
    }
}
