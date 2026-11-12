<?php

namespace Modules\Avaliacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoPrazo;
use Modules\Avaliacao\Models\AvaliacaoRegra;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Enums\Perfil;
use Modules\Turma\Models\NivelAcademico;
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
            'ano_lectivo_id' => $this->c['ano']->id, 'nome' => 'Regra', 'nivel_ids' => [$this->c['nivel']->id], 'com_exame' => false, 'com_recurso' => false, 'sigla_prova' => 'PT', 'escala_maxima' => 10, 'nota_minima' => '5',
            'peso_mac' => '50', 'peso_pt' => '50', 'casas_decimais' => 2, 'modo_arredondamento' => 0, 'tratamento_negativas' => 0, ...$o,
        ];
    }

    public function test_configuracao_sem_permissao_recebe_403(): void
    {
        $professor = $this->criarUtilizadorComPerfil(Perfil::PROFESSOR, 'prof@example.com');

        $this->actingAs($professor)->get('/avaliacao/configuracao')->assertForbidden();
        $this->actingAs($professor)->post('/avaliacao/configuracao/regras', $this->regraPayload())->assertForbidden();
        $this->assertSame(0, AvaliacaoRegra::count());
    }

    public function test_admin_ve_as_regras_do_ano_ja_criadas_com_as_classes_ligadas(): void
    {
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com');

        $this->actingAs($admin)->get('/avaliacao/configuracao')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Avaliacao/Configuracao/Index')
            ->where('ano_id', $this->c['ano']->id)
            ->has('periodos', 3)
            ->has('regras', 1)
            ->where('regras.0.nome', '3.ª a 5.ª classe')
            ->where('regras.0.nivel_ids', [$this->c['nivel']->id])
            ->where('regras.0.escala_maxima', 10)
            ->where('regras.0.peso_mac', '50.00')
            ->where('regras.0.com_exame', false)
            ->where('niveis.0.sem_regra', false));
    }

    public function test_as_classes_sem_regra_aparecem_como_tal_e_a_creche_e_o_pre_escolar_como_qualitativos(): void
    {
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com');
        $this->actingAs($admin)->get('/avaliacao/configuracao')->assertOk(); // o ano arranca com as suas regras
        foreach ([['PRE_I', EtapaEnsinoEnum::PRE_ESCOLAR, 2], ['SEC_10', EtapaEnsinoEnum::SECUNDARIO, 3]] as [$codigo, $etapa, $ordem]) {
            NivelAcademico::create(['estabelecimento_id' => Estabelecimento::current()->id, 'codigo' => $codigo, 'nome' => $codigo, 'etapa_ensino' => $etapa, 'ordem' => $ordem]);
        }

        $this->actingAs($admin)->get('/avaliacao/configuracao')->assertInertia(fn (Assert $p) => $p
            ->where('niveis.0.sem_regra', false)
            ->where('niveis.1.qualitativo', true)->where('niveis.1.sem_regra', true)
            ->where('niveis.2.qualitativo', false)->where('niveis.2.sem_regra', true));
    }

    public function test_admin_guarda_regra_define_prazo_e_prolonga(): void
    {
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com');

        $this->actingAs($admin)->post('/avaliacao/configuracao/regras', $this->regraPayload(['peso_mac' => '60', 'peso_pt' => '40']))->assertRedirect();
        $regra = AvaliacaoRegra::firstOrFail();
        $this->assertSame('60.00', $regra->peso_mac);

        $this->actingAs($admin)->post('/avaliacao/configuracao/prazos', [
            'periodo_id' => $this->c['periodos'][1]->id, 'tipo' => TipoInstrumento::MAC->value, 'data_limite' => '2026-04-10',
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
