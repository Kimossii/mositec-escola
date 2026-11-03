<?php

namespace Modules\Avaliacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoPrazo;
use Modules\Avaliacao\Models\AvaliacaoRegra;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Permissao\Enums\Perfil;
use Tests\TestCase;

class ConfiguracaoAvaliacaoHttpTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    private array $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->c = $this->cenarioDeAvaliacao();
    }

    private function regraPayload(array $o = []): array
    {
        return [
            'ano_lectivo_id' => $this->c['ano']->id, 'nivel_academico_id' => $this->c['nivel']->id, 'escala_maxima' => 10, 'nota_minima' => '5',
            'peso_mac' => '50', 'peso_pt' => '50', 'casas_decimais' => 2, 'modo_arredondamento' => 0, 'reprova_por_aproveitamento' => true, ...$o,
        ];
    }

    public function test_configuracao_sem_permissao_recebe_403(): void
    {
        $professor = $this->criarUtilizadorComPerfil(Perfil::PROFESSOR, 'prof@example.com');

        $this->actingAs($professor)->get('/avaliacao/configuracao')->assertForbidden();
        $this->actingAs($professor)->post('/avaliacao/configuracao/regras', $this->regraPayload())->assertForbidden();
        $this->assertSame(0, AvaliacaoRegra::count());
    }

    public function test_admin_ve_as_classes_com_a_sugestao_por_defeito(): void
    {
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com');

        $this->actingAs($admin)->get('/avaliacao/configuracao')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Avaliacao/Configuracao/Index')
            ->where('ano_id', $this->c['ano']->id)
            ->has('periodos', 3)
            ->where('classes.0.codigo', 'PRI_3')
            ->where('classes.0.regra', null)
            ->where('classes.0.sugestao.nota_minima', '5'));
    }

    public function test_admin_guarda_regra_define_prazo_e_prolonga(): void
    {
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com');

        $this->actingAs($admin)->post('/avaliacao/configuracao/regras', $this->regraPayload(['peso_mac' => '60', 'peso_pt' => '40']))->assertRedirect();
        $regra = AvaliacaoRegra::firstOrFail();
        $this->assertSame('60.00', $regra->peso_mac);

        $this->actingAs($admin)->post('/avaliacao/configuracao/prazos', [
            'regra_id' => $regra->id, 'periodo_id' => $this->c['periodos'][1]->id, 'tipo' => TipoInstrumento::MAC->value, 'data_limite' => '2026-04-10',
        ])->assertRedirect();
        $prazo = AvaliacaoPrazo::firstOrFail();

        $this->actingAs($admin)->post("/avaliacao/configuracao/prazos/{$prazo->id}/excepcoes", [
            'data_limite' => '2026-04-20', 'motivo' => 'Greve', 'turma_id' => $this->c['turma']->id,
        ])->assertRedirect();
        $this->assertSame(1, $prazo->excepcoes()->count());
    }

    public function test_pesos_invalidos_voltam_com_erro(): void
    {
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com');

        $this->actingAs($admin)->post('/avaliacao/configuracao/regras', $this->regraPayload(['peso_mac' => '50', 'peso_pt' => '40']))
            ->assertSessionHasErrors('peso_mac');
        $this->assertSame(0, AvaliacaoRegra::count());
    }
}
