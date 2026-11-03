<?php

namespace Modules\Avaliacao\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Avaliacao\Actions\CopiarRegrasDoAnoAnteriorAction;
use Modules\Avaliacao\Actions\GuardarRegraAvaliacaoAction;
use Modules\Avaliacao\DTO\RegraAvaliacaoDTO;
use Modules\Avaliacao\Enums\ModoArredondamento;
use Modules\Avaliacao\Models\AvaliacaoRegra;
use Modules\Avaliacao\Services\RegraPadraoService;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Estabelecimento\Models\Estabelecimento;
use Tests\TestCase;

class RegraDeAvaliacaoTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    private function dto(array $c, array $o = []): RegraAvaliacaoDTO
    {
        return new RegraAvaliacaoDTO(
            ano_lectivo_id: $o['ano'] ?? $c['ano']->id,
            nivel_academico_id: $c['nivel']->id,
            escala_maxima: $o['escala'] ?? 10,
            nota_minima: $o['minima'] ?? '5',
            peso_mac: $o['mac'] ?? '50',
            peso_pt: $o['pt'] ?? '50',
            peso_mt1: $o['mt1'] ?? null,
            peso_mt2: $o['mt2'] ?? null,
            peso_mt3: $o['mt3'] ?? null,
        );
    }

    public function test_regra_padrao_por_codigo_de_nivel(): void
    {
        $s = new RegraPadraoService();

        $this->assertSame(['escala_maxima' => 10, 'nota_minima' => '5', 'reprova_por_aproveitamento' => false], $s->paraNivel('PRI_1'));
        $this->assertSame(['escala_maxima' => 10, 'nota_minima' => '5', 'reprova_por_aproveitamento' => true], $s->paraNivel('PRI_6'));
        $this->assertSame(['escala_maxima' => 20, 'nota_minima' => '10', 'reprova_por_aproveitamento' => true], $s->paraNivel('SEC_9'));
        $this->assertNull($s->paraNivel('PRE_I'));
        $this->assertNull($s->paraNivel('SEC_10'));
    }

    public function test_guardar_cria_e_depois_actualiza_a_mesma_regra(): void
    {
        $c = $this->cenarioDeAvaliacao();
        $acao = app(GuardarRegraAvaliacaoAction::class);

        $regra = $acao->executar($this->dto($c));
        $this->assertSame(ModoArredondamento::HALF_UP, $regra->modo_arredondamento);
        $this->assertSame('Arredondar para cima a partir de metade', $regra->modo_arredondamento_descricao);

        $acao->executar($this->dto($c, ['mac' => '60', 'pt' => '40']));

        $this->assertSame(1, AvaliacaoRegra::count());
        $this->assertSame('60.00', AvaliacaoRegra::first()->peso_mac);
    }

    public function test_guardar_rejeita_pesos_que_nao_somam_100(): void
    {
        $c = $this->cenarioDeAvaliacao();

        $this->expectException(ValidationException::class);
        app(GuardarRegraAvaliacaoAction::class)->executar($this->dto($c, ['mac' => '50', 'pt' => '40']));
    }

    public function test_guardar_rejeita_pesos_de_trimestres_incompletos_ou_que_nao_somam_100(): void
    {
        $c = $this->cenarioDeAvaliacao();
        $acao = app(GuardarRegraAvaliacaoAction::class);

        try {
            $acao->executar($this->dto($c, ['mt1' => '50', 'mt2' => '50']));
            $this->fail('Esperava ValidationException (incompletos).');
        } catch (ValidationException) {
            $this->assertSame(0, AvaliacaoRegra::count());
        }

        $this->expectException(ValidationException::class);
        $acao->executar($this->dto($c, ['mt1' => '30', 'mt2' => '30', 'mt3' => '30']));
    }

    public function test_guardar_aceita_pesos_de_trimestres_que_somam_100(): void
    {
        $c = $this->cenarioDeAvaliacao();

        $regra = app(GuardarRegraAvaliacaoAction::class)->executar($this->dto($c, ['mt1' => '25', 'mt2' => '25', 'mt3' => '50']));

        $this->assertSame(['25.00', '25.00', '50.00'], $regra->pesosDosTrimestres());
    }

    public function test_guardar_rejeita_minima_acima_da_escala(): void
    {
        $c = $this->cenarioDeAvaliacao();

        $this->expectException(ValidationException::class);
        app(GuardarRegraAvaliacaoAction::class)->executar($this->dto($c, ['minima' => '11']));
    }

    public function test_segunda_regra_para_o_mesmo_ano_e_nivel_e_rejeitada_pela_bd(): void
    {
        $c = $this->cenarioDeAvaliacao();
        $base = ['ano_lectivo_id' => $c['ano']->id, 'nivel_academico_id' => $c['nivel']->id, 'escala_maxima' => 10, 'nota_minima' => 5, 'modo_arredondamento' => ModoArredondamento::HALF_UP];

        AvaliacaoRegra::create($base);

        $this->expectException(QueryException::class);
        AvaliacaoRegra::create($base);
    }

    public function test_copiar_traz_as_regras_do_ano_anterior_e_nao_sobrescreve(): void
    {
        $c = $this->cenarioDeAvaliacao();
        app(GuardarRegraAvaliacaoAction::class)->executar($this->dto($c, ['mac' => '60', 'pt' => '40']));

        $novo = AnoLectivo::create([
            'estabelecimento_id' => Estabelecimento::current()->id, 'nome' => '2027', 'data_inicio' => '2027-01-01',
            'data_fim' => '2027-12-31', 'estado' => $c['ano']->estado,
        ]);

        $copiar = app(CopiarRegrasDoAnoAnteriorAction::class);
        $this->assertSame(1, $copiar->executar($novo->id));
        $this->assertSame('60.00', AvaliacaoRegra::where('ano_lectivo_id', $novo->id)->first()->peso_mac);

        app(GuardarRegraAvaliacaoAction::class)->executar($this->dto($c, ['ano' => $novo->id, 'mac' => '70', 'pt' => '30']));
        $this->assertSame(0, $copiar->executar($novo->id));
        $this->assertSame('70.00', AvaliacaoRegra::where('ano_lectivo_id', $novo->id)->first()->peso_mac);
    }

    public function test_regra_de_outro_tenant_nao_aparece(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Outra Escola', 'outra.localhost');
        $this->noTenant($outro, function () {
            $c = $this->cenarioDeAvaliacao('PRI_3', 'B');
            app(GuardarRegraAvaliacaoAction::class)->executar($this->dto($c));
            $this->assertSame(1, AvaliacaoRegra::count());
        });

        $this->assertSame(0, AvaliacaoRegra::count());
    }
}
