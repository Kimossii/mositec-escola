<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Financeiro\Enums\Periodicidade;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Services\ResolvePlanoAplicavel;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

/**
 * Alvos que apontam para nível/turno/turma eliminados (soft delete) ficam "obsoletos": nunca
 * casam no resolvedor, aparecem marcados na listagem e são retirados ao guardar.
 */
class AlvosObsoletosTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    private const BASE = 'financeiro.configuracao.planos-propina.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function payload(int $anoId, array $sobrepor = []): array
    {
        return array_merge([
            'ano_lectivo_id' => $anoId,
            'nome' => 'Propina',
            'periodicidade' => Periodicidade::MENSAL->value,
            'valor' => '25000',
            'mes_inicio' => 9,
            'mes_fim' => 6,
            'alvos' => [],
        ], $sobrepor);
    }

    public function test_resolvedor_ignora_alvo_de_turma_eliminada_sem_degradar_para_geral(): void
    {
        $ano = $this->anoLectivo();
        $nivel = $this->nivel('N1');
        $turmaEliminada = $this->turma($ano, $nivel);
        $this->plano($ano, 'So da turma', [], [['turma' => $turmaEliminada]]);
        $outra = $this->turma($ano, $nivel);
        $turmaEliminada->delete();

        $this->assertFalse(app(ResolvePlanoAplicavel::class)->paraTurma($outra)->temPlano());
        $this->assertFalse(app(ResolvePlanoAplicavel::class)->paraTurma($turmaEliminada)->temPlano());
    }

    public function test_resolvedor_ignora_alvo_de_nivel_eliminado(): void
    {
        $ano = $this->anoLectivo();
        $nivel = $this->nivel('N1');
        $turma = $this->turma($ano, $nivel);
        $this->plano($ano, 'Do nivel', [], [['nivel' => $nivel]]);
        $nivel->delete();

        $this->assertFalse(app(ResolvePlanoAplicavel::class)->paraTurma($turma)->temPlano());
    }

    public function test_index_mostra_alvos_eliminados_com_nome_e_marca(): void
    {
        $ano = $this->anoLectivo();
        $nivel = $this->nivel('N1');
        $turno = $this->turno('Noite');
        $this->plano($ano, 'Propina', [], [['nivel' => $nivel], ['turno' => $turno]]);
        $nivel->delete();

        $this->actingAs($this->adminEscola())->get(route(self::BASE . 'index'))
            ->assertInertia(fn (Assert $p) => $p
                ->where('planos.data.0.alvos.0.nivel_nome', 'Nível N1')
                ->where('planos.data.0.alvos.0.eliminado', true)
                ->where('planos.data.0.alvos.1.turno_nome', 'Noite')
                ->where('planos.data.0.alvos.1.eliminado', false));
    }

    public function test_actualizar_retira_alvos_eliminados_e_mantem_os_validos(): void
    {
        $ano = $this->anoLectivo();
        $morto = $this->nivel('N1');
        $vivo = $this->turno('Noite');
        $plano = $this->plano($ano, 'Propina', [], [['nivel' => $morto], ['turno' => $vivo]]);
        $morto->delete();

        $this->actingAs($this->adminEscola())->from('/x')
            ->put(route(self::BASE . 'update', $plano), $this->payload($ano->id, ['alvos' => [
                ['nivel_academico_id' => $morto->id], ['turno_id' => $vivo->id],
            ]]))
            ->assertSessionHasNoErrors();

        $alvos = $plano->fresh()->alvos;
        $this->assertCount(1, $alvos);
        $this->assertSame($vivo->id, $alvos->first()->turno_id);
    }

    public function test_actualizar_rejeita_quando_todos_os_alvos_foram_eliminados(): void
    {
        $ano = $this->anoLectivo();
        $morta = $this->turma($ano, $this->nivel('N1'));
        $plano = $this->plano($ano, 'Propina', [], [['turma' => $morta]]);
        $morta->delete();

        $this->actingAs($this->adminEscola())->from('/x')
            ->put(route(self::BASE . 'update', $plano), $this->payload($ano->id, ['alvos' => [['turma_id' => $morta->id]]]))
            ->assertSessionHasErrors(['alvos' => 'Todos os alvos do plano foram eliminados; escolha novos alvos ou desactive o plano.']);

        $this->assertSame(1, $plano->fresh()->alvos()->count());
    }

    public function test_actualizar_sem_alvos_de_proposito_continua_a_ser_geral(): void
    {
        $ano = $this->anoLectivo();
        $plano = $this->plano($ano, 'Propina', [], [['nivel' => $this->nivel('N1')]]);

        $this->actingAs($this->adminEscola())
            ->put(route(self::BASE . 'update', $plano), $this->payload($ano->id, ['confirmar_plano_geral' => true]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $plano->fresh()->alvos()->count());
    }

    public function test_criar_com_alvo_eliminado_e_rejeitado(): void
    {
        $ano = $this->anoLectivo();
        $turno = $this->turno('Noite');
        $turno->delete();

        $this->actingAs($this->adminEscola())->from('/x')
            ->post(route(self::BASE . 'store'), $this->payload($ano->id, ['alvos' => [['turno_id' => $turno->id]]]))
            ->assertSessionHasErrors('alvos.0.turno_id');

        $this->assertSame(0, PlanoPropina::count());
    }

    public function test_alvo_eliminado_nao_submetido_nao_e_aceite_em_alvo_novo_de_outro_plano(): void
    {
        $ano = $this->anoLectivo();
        $morto = $this->turno('Noite');
        $this->plano($ano, 'A', [], [['turno' => $morto]]);
        $outro = $this->plano($ano, 'B');
        $morto->delete();

        $this->actingAs($this->adminEscola())->from('/x')
            ->put(route(self::BASE . 'update', $outro), $this->payload($ano->id, ['nome' => 'B', 'alvos' => [['turno_id' => $morto->id]]]))
            ->assertSessionHasErrors('alvos.0.turno_id');
    }

    public function test_alvos_vazios_ou_omitidos_com_plano_so_de_obsoletos_sao_rejeitados(): void
    {
        $ano = $this->anoLectivo();
        $morta = $this->turma($ano, $this->nivel('N1'));
        $plano = $this->plano($ano, 'Propina', [], [['turma' => $morta]]);
        $morta->delete();
        $this->actingAs($this->adminEscola());
        $msg = 'Todos os alvos do plano foram eliminados; escolha novos alvos ou desactive o plano.';

        $this->from('/x')->put(route(self::BASE . 'update', $plano), $this->payload($ano->id, ['nome' => 'Outro', 'alvos' => []]))
            ->assertSessionHasErrors(['alvos' => $msg]);

        $omitido = $this->payload($ano->id, ['nome' => 'Outro']);
        unset($omitido['alvos']);
        $this->from('/x')->put(route(self::BASE . 'update', $plano), $omitido)->assertSessionHasErrors(['alvos' => $msg]);

        $plano->refresh();
        $this->assertSame('Propina', $plano->nome);
        $this->assertSame(1, $plano->alvos()->count());
    }

    public function test_plano_com_alvos_mistos_aplica_se_so_pelo_valido(): void
    {
        $ano = $this->anoLectivo();
        $morto = $this->nivel('N1');
        $vivo = $this->nivel('N2');
        $plano = $this->plano($ano, 'Misto', [], [['nivel' => $morto], ['nivel' => $vivo]]);
        $turmaMorta = $this->turma($ano, $morto);
        $turmaViva = $this->turma($ano, $vivo);
        $morto->delete();

        $this->assertSame($plano->id, app(ResolvePlanoAplicavel::class)->paraTurma($turmaViva)->plano->id);
        $this->assertFalse(app(ResolvePlanoAplicavel::class)->paraTurma($turmaMorta)->temPlano());
    }
}
