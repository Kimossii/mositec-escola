<?php

namespace Modules\Avaliacao\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Avaliacao\Actions\GarantirRegrasDoAnoAction;
use Modules\Avaliacao\Actions\EliminarRegraAvaliacaoAction;
use Modules\Avaliacao\Actions\GuardarRegraAvaliacaoAction;
use Modules\Avaliacao\Actions\GuardarRegraQualitativaAction;
use Modules\Avaliacao\DTO\RegraQualitativaDTO;
use Modules\Avaliacao\DTO\RegraAvaliacaoDTO;
use Modules\Avaliacao\Enums\ModoArredondamento;
use Modules\Avaliacao\Models\AvaliacaoRegra;
use Modules\Avaliacao\Models\AvaliacaoRegraNivel;
use Modules\Turma\Models\NivelAcademico;
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
            nivel_ids: $o['niveis'] ?? [$c['nivel']->id],
            regra_id: $o['regra_id'] ?? null,
            nome: $o['nome'] ?? '',
            nova: $o['nova'] ?? false,
            escala_maxima: $o['escala'] ?? 10,
            nota_minima: $o['minima'] ?? '5',
            peso_mac: $o['mac'] ?? '50',
            peso_pt: $o['pt'] ?? '50',
            peso_mt1: $o['mt1'] ?? null,
            peso_mt2: $o['mt2'] ?? null,
            peso_mt3: $o['mt3'] ?? null,
        );
    }

    public function test_as_regras_por_defeito_agrupam_as_classes_como_a_escola_as_vive(): void
    {
        $s = new RegraPadraoService();

        $this->assertSame(
            ['Creche e Pré-Escolar', '1.ª e 2.ª classe', '3.ª a 5.ª classe', '6.ª classe', '7.ª e 8.ª classe', '9.ª classe'],
            array_column($s->regras(), 'nome'),
        );
        $this->assertSame('1.ª e 2.ª classe', $s->regraDe('PRI_2')['nome']);
        $this->assertFalse($s->regraDe('PRI_1')['reprova_por_aproveitamento']);
        $this->assertSame([20, '10', false, true], [$s->regraDe('SEC_8')['escala_maxima'], $s->regraDe('SEC_8')['nota_minima'], $s->temExameProprio('SEC_8'), $s->temRecurso('SEC_8')]);
        $this->assertSame([true, true], [$s->temExameProprio('PRI_6'), $s->temRecurso('PRI_6')]);
        $this->assertSame([true, true], [$s->temExameProprio('SEC_9'), $s->temRecurso('SEC_9')]);
        $this->assertNull($s->regraDe('SEC_10'));
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

    public function test_uma_classe_so_pode_seguir_uma_regra_por_ano_na_bd(): void
    {
        $c = $this->cenarioDeAvaliacao();
        app(GuardarRegraAvaliacaoAction::class)->executar($this->dto($c));
        $b = AvaliacaoRegra::create(['ano_lectivo_id' => $c['ano']->id, 'nome' => 'Outra', 'escala_maxima' => 10, 'nota_minima' => 5, 'modo_arredondamento' => ModoArredondamento::HALF_UP]);

        $this->expectException(QueryException::class);
        AvaliacaoRegraNivel::create(['ano_lectivo_id' => $c['ano']->id, 'nivel_academico_id' => $c['nivel']->id, 'regra_id' => $b->id]);
    }

    public function test_uma_classe_que_ja_segue_uma_regra_nao_se_liga_a_outra(): void
    {
        $c = $this->cenarioDeAvaliacao();
        $quarta = NivelAcademico::create(['estabelecimento_id' => $c['nivel']->estabelecimento_id, 'codigo' => 'PRI_4', 'nome' => '4.ª Classe', 'ordem' => 4, 'etapa_ensino' => 3]);
        $acao = app(GuardarRegraAvaliacaoAction::class);

        $juntas = $acao->executar($this->dto($c, ['niveis' => [$c['nivel']->id, $quarta->id]]));
        $this->assertEqualsCanonicalizing([$c['nivel']->id, $quarta->id], $juntas->niveis()->pluck('nivel_academico_id')->all());

        // Uma regra nova com a 4.ª é recusada, e nada fica criado nem mudado.
        try {
            $acao->executar($this->dto($c, ['niveis' => [$quarta->id], 'nome' => 'Só a 4.ª', 'nova' => true]));
            $this->fail('A 4.ª já segue uma regra.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('já segue a regra', $e->errors()['nivel_ids'][0]);
        }
        $this->assertSame(1, AvaliacaoRegra::count());
        $this->assertCount(2, $juntas->fresh()->niveis);

        // Tirada da primeira, já se liga a uma regra nova.
        $acao->executar($this->dto($c, ['niveis' => [$c['nivel']->id], 'regra_id' => $juntas->id]));
        $nova = $acao->executar($this->dto($c, ['niveis' => [$quarta->id], 'nome' => 'Só a 4.ª', 'nova' => true]));
        $this->assertSame([$quarta->id], $nova->niveis()->pluck('nivel_academico_id')->all());
        $this->assertSame(2, AvaliacaoRegra::count());
    }

    public function test_a_creche_e_o_pre_escolar_so_seguem_regras_qualitativas_e_as_outras_so_numericas(): void
    {
        $c = $this->cenarioDeAvaliacao();
        $pre = NivelAcademico::create(['estabelecimento_id' => $c['nivel']->estabelecimento_id, 'codigo' => 'PRE_I', 'nome' => 'Iniciação', 'ordem' => 1, 'etapa_ensino' => 2]);

        try {
            app(GuardarRegraAvaliacaoAction::class)->executar($this->dto($c, ['niveis' => [$pre->id]]));
            $this->fail('A Iniciação não pode seguir uma regra numérica.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('nivel_ids', $e->errors());
        }

        $this->expectException(ValidationException::class);
        app(GuardarRegraQualitativaAction::class)->executar(new RegraQualitativaDTO($c['ano']->id, [$c['nivel']->id], null));
    }

    public function test_uma_regra_ainda_nao_usada_elimina_se_mesmo_com_classes_e_as_classes_ficam_sem_regra(): void
    {
        $c = $this->cenarioDeAvaliacao();
        $regra = app(GuardarRegraAvaliacaoAction::class)->executar($this->dto($c));
        $this->assertSame(1, AvaliacaoRegraNivel::count());

        app(EliminarRegraAvaliacaoAction::class)->executar($regra->id, false);

        $this->assertSame(0, AvaliacaoRegra::count());
        $this->assertSame(0, AvaliacaoRegraNivel::count(), 'as classes ficam sem regra');
    }

    public function test_uma_regra_ja_usada_nao_se_elimina(): void
    {
        $c = $this->cenarioDeAvaliacao();
        $regra = app(GuardarRegraAvaliacaoAction::class)->executar($this->dto($c));
        \Modules\Avaliacao\Models\AvaliacaoInstrumento::create(['turma_id' => $c['turma']->id, 'disciplina_id' => $c['disciplina']->id, 'periodo_id' => $c['periodos'][1]->id, 'tipo' => \Modules\Avaliacao\Enums\TipoInstrumento::MAC, 'nome' => 'A1', 'obrigatorio' => true]);

        $admin = $this->criarUtilizadorComPerfil(\Modules\Permissao\Enums\Perfil::ADMIN_ESCOLA, 'admin@example.com');
        $props = \Inertia\Testing\AssertableInertia::fromTestResponse($this->actingAs($admin)->get('/avaliacao/configuracao'))->toArray()['props'];
        $this->assertTrue(collect($props['regras'])->firstWhere('id', $regra->id)['em_uso']);

        try {
            app(EliminarRegraAvaliacaoAction::class)->executar($regra->id, false);
            $this->fail('A regra já foi usada.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('regra_id', $e->errors());
        }
        $this->assertSame(1, AvaliacaoRegra::count());
    }

    public function test_um_ano_novo_herda_as_regras_do_anterior_com_as_classes_e_so_actua_quando_nao_tem_nenhuma(): void
    {
        $c = $this->cenarioDeAvaliacao();
        app(GuardarRegraAvaliacaoAction::class)->executar($this->dto($c, ['mac' => '60', 'pt' => '40', 'nome' => 'Minha regra']));

        $novo = AnoLectivo::create([
            'estabelecimento_id' => Estabelecimento::current()->id, 'nome' => '2027', 'data_inicio' => '2027-01-01',
            'data_fim' => '2027-12-31', 'estado' => $c['ano']->estado,
        ]);

        $garantir = app(GarantirRegrasDoAnoAction::class);
        $garantir->executar($novo->id);

        $herdada = AvaliacaoRegra::where('ano_lectivo_id', $novo->id)->sole();
        $this->assertSame(['Minha regra', '60.00'], [$herdada->nome, $herdada->peso_mac]);
        $this->assertSame([$c['nivel']->id], $herdada->niveis()->pluck('nivel_academico_id')->all());

        $herdada->update(['peso_mac' => '70', 'peso_pt' => '30']);
        $garantir->executar($novo->id);
        $this->assertSame(1, AvaliacaoRegra::where('ano_lectivo_id', $novo->id)->count(), 'já tem regras: não se mexe');
        $this->assertSame('70.00', $herdada->fresh()->peso_mac);
    }

    public function test_o_primeiro_ano_arranca_com_as_regras_por_defeito_ligadas_as_classes_que_a_escola_tem(): void
    {
        $c = $this->cenarioDeAvaliacao();
        foreach (['PRI_4' => 4, 'PRI_6' => 6, 'SEC_9' => 9] as $codigo => $n) {
            NivelAcademico::create(['estabelecimento_id' => $c['nivel']->estabelecimento_id, 'codigo' => $codigo, 'nome' => "{$n}.ª Classe", 'ordem' => $n, 'etapa_ensino' => 3]);
        }

        app(GarantirRegrasDoAnoAction::class)->executar($c['ano']->id);

        $regras = AvaliacaoRegra::where('ano_lectivo_id', $c['ano']->id)->with('niveis.nivelAcademico')->get()->keyBy('nome');
        $this->assertEqualsCanonicalizing(['3.ª a 5.ª classe', '6.ª classe', '9.ª classe'], $regras->keys()->all(), 'só as regras de classes que a escola tem');
        $this->assertEqualsCanonicalizing(['PRI_3', 'PRI_4'], $regras['3.ª a 5.ª classe']->niveis->map(fn ($l) => $l->nivelAcademico->codigo)->all());
        $this->assertSame([true, true, '10.00'], [$regras['6.ª classe']->com_exame, $regras['6.ª classe']->com_recurso, (string) $regras['9.ª classe']->nota_minima]);
        $this->assertSame([true, true, 20], [$regras['9.ª classe']->com_exame, $regras['9.ª classe']->com_recurso, $regras['9.ª classe']->escala_maxima]);
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
