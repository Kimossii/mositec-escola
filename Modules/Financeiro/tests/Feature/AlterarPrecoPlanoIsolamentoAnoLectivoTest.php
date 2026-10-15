<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Financeiro\Actions\AlterarPrecoPlanoAction;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Models\AlteracaoPrecoPlano;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaAjuste;
use Modules\Financeiro\Services\AlteracaoPrecoPlanoService;
use Modules\Financeiro\Tests\Concerns\ComAlteracaoPreco;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Matricula\Models\Matricula;
use Tests\TestCase;

/**
 * Spec §14 "Regra obrigatória: isolamento por ano lectivo" — os 8 testes obrigatórios. Pré-visualização (Task 7) e escrita (Task 10).
 */
class AlterarPrecoPlanoIsolamentoAnoLectivoTest extends TestCase
{
    use ComAlteracaoPreco;
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    private const APLICAR = ModoAlteracaoPreco::APLICAR_GERADAS;
    private const MANTER = ModoAlteracaoPreco::MANTER_GERADAS;

    /**
     * @return array{anoA: mixed, anoB: mixed, turmaA: mixed, turmaB: mixed, planoA: PlanoPropina, planoB: PlanoPropina, matriculaA: Matricula, matriculaB: Matricula}
     */
    private function cenarioDoisAnos(): array
    {
        $anoA = $this->anoLectivo('2026/2027', '2026-09-01', '2027-07-31');
        $anoB = $this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31');
        $nivel = $this->nivel('N1');
        $turmaA = $this->turma($anoA, $nivel);
        $turmaB = $this->turma($anoB, $nivel);
        $planoA = $this->plano($anoA, 'Propina Mensal');
        $planoB = $this->plano($anoB, 'Propina Mensal');
        $matriculaA = $this->matriculaActiva($turmaA);
        // Mesmo aluno, renovado para o ano seguinte.
        $matriculaB = Matricula::create([
            'aluno_id' => $matriculaA->aluno_id,
            'turma_id' => $turmaB->id,
            'ano_lectivo_id' => $anoB->id,
            'numero_registo_matricula' => 'MR-' . uniqid(),
            'data_matricula' => '2027-09-01',
            'estado' => $matriculaA->estado->value,
        ]);

        foreach (range(1, 10) as $ordem) {
            $this->propina($matriculaA, $planoA, $ordem);
            $this->propina($matriculaB, $planoB, $ordem);
        }

        return compact('anoA', 'anoB', 'turmaA', 'turmaB', 'planoA', 'planoB', 'matriculaA', 'matriculaB');
    }

    public function test_4_mes_de_efeito_fora_do_calendario_do_ano_do_plano_e_recusado(): void
    {
        ['planoA' => $planoA] = $this->cenarioDoisAnos();

        // O mesmo mês noutro ano civil (o de B), e um mês depois do fim do plano.
        foreach (['2027-09', '2028-02', '2027-07', '2025-10'] as $mes) {
            $this->assertRecusa('mes_efeito', 'no seu ano lectivo', fn () => $this->previa($planoA, $mes, 3_000_000, self::APLICAR));
        }
    }

    public function test_5_e_6_propina_de_outro_ano_injectada_no_conjunto_faz_recusar_a_previsualizacao_e_regista_no_log(): void
    {
        ['planoA' => $planoA, 'matriculaB' => $matriculaB] = $this->cenarioDoisAnos();
        $intrusa = Propina::query()->where('matricula_id', $matriculaB->id)->where('ordem', 7)->firstOrFail();
        // Fixture que viola a invariante da geração (§3): só possível por SQL directo (nunca pela app).
        DB::table('propinas')->where('id', $intrusa->id)->update(['plano_propina_id' => $planoA->id]);
        Log::spy();

        $this->assertRecusa('plano', 'outro ano lectivo', fn () => $this->previa($planoA, '2027-02', 3_000_000, self::APLICAR));
        $this->assertRecusa('plano', 'outro ano lectivo', fn () => $this->previa($planoA, '2027-02', 3_000_000, self::MANTER));

        Log::shouldHaveReceived('error')->withArgs(fn (string $mensagem, array $contexto) => $mensagem === 'financeiro.alterar-preco.propinas-de-outro-ano'
            && $contexto['propina_ids'] === [$intrusa->id] && $contexto['plano_propina_id'] === $planoA->id);
    }

    public function test_5_matricula_de_outro_ano_com_propina_do_plano_tambem_faz_recusar(): void
    {
        ['planoA' => $planoA, 'matriculaA' => $matriculaA, 'anoB' => $anoB] = $this->cenarioDoisAnos();
        DB::table('matriculas')->where('id', $matriculaA->id)->update(['ano_lectivo_id' => $anoB->id]);

        $this->assertRecusa('plano', 'outro ano lectivo', fn () => $this->previa($planoA, '2026-09', 3_000_000, self::APLICAR));
    }

    public function test_5_matricula_apagada_de_outro_ano_com_propina_do_plano_tambem_faz_recusar(): void
    {
        ['planoA' => $planoA, 'matriculaA' => $matriculaA, 'anoB' => $anoB] = $this->cenarioDoisAnos();
        DB::table('matriculas')->where('id', $matriculaA->id)->update(['ano_lectivo_id' => $anoB->id, 'deleted_at' => '2026-10-01 10:00:00']);

        $this->assertRecusa('plano', 'outro ano lectivo', fn () => $this->previa($planoA, '2026-09', 3_000_000, self::APLICAR));
    }

    public function test_6_previsualizacao_mostra_o_ano_e_a_contagem_zero_de_outros_anos(): void
    {
        ['planoA' => $planoA, 'anoA' => $anoA, 'matriculaB' => $matriculaB] = $this->cenarioDoisAnos();

        $previa = $this->previa($planoA, '2027-02', 3_000_000, self::APLICAR)->toArray();

        $this->assertSame(['id' => $anoA->id, 'nome' => '2026/2027'], $previa['ano_lectivo']);
        $this->assertSame(0, $previa['propinas_de_outros_anos']);
        $this->assertSame('Nenhuma propina de outros anos lectivos será alterada.', $previa['garantia_outros_anos']);
        $idsDoOutroAno = Propina::query()->where('matricula_id', $matriculaB->id)->pluck('id')->all();
        $this->assertSame([], array_values(array_intersect($idsDoOutroAno, array_column($previa['afectadas'], 'propina_id'))));
        $this->assertSame([], array_values(array_intersect($idsDoOutroAno, array_column($previa['inalteradas'], 'propina_id'))));
    }

    public function test_7_impressao_digital_muda_se_o_ano_do_plano_mudar(): void
    {
        ['planoA' => $planoA, 'anoB' => $anoB] = $this->cenarioDoisAnos();
        $servico = app(AlteracaoPrecoPlanoService::class);
        $pedido = $this->pedidoAlteracao('2027-02', 3_000_000, self::MANTER);
        $candidatas = Propina::query()->where('plano_propina_id', $planoA->id)->where('periodo_inicio', '>=', '2027-02-01')->get();
        $plano = $planoA->fresh('alvos');
        $antes = $servico->impressaoDigital($plano, $pedido, $candidatas);

        $this->assertSame($antes, $this->previa($planoA, '2027-02', 3_000_000, self::MANTER)->impressaoDigital);

        // Só em memória: o ano de um plano é imutável na app; prova-se que a impressão o inclui…
        $plano->setRawAttributes(array_merge($plano->getAttributes(), ['ano_lectivo_id' => $anoB->id]));
        $this->assertNotSame($antes, $servico->impressaoDigital($plano, $pedido, $candidatas));

        // …e que, com o ano trocado, as propinas do plano passam a ser "de outro ano" e a pré-visualização recusa.
        $this->assertRecusa('plano', 'outro ano lectivo', fn () => $servico->preVisualizar($plano, $pedido));
    }

    /** @return array<int, array<string, mixed>> as propinas de uma matrícula, em cru, por id */
    private function propinasDe(Matricula $matricula): array
    {
        return Propina::query()->where('matricula_id', $matricula->id)->orderBy('id')->get()->mapWithKeys(fn (Propina $p) => [$p->id => $p->getAttributes()])->all();
    }

    public function test_1_duas_operacoes_em_anos_diferentes_nao_se_tocam(): void
    {
        ['planoA' => $planoA, 'planoB' => $planoB, 'matriculaA' => $matriculaA, 'matriculaB' => $matriculaB] = $this->cenarioDoisAnos();
        $deB = $this->propinasDe($matriculaB);

        $this->alterarPreco($planoA, '2027-02', 3_000_000, self::APLICAR);

        $this->assertSame($deB, $this->propinasDe($matriculaB), 'Propinas de B intactas: valor, valor_pago, plano, ordem.');
        $this->assertSame(0, PropinaAjuste::query()->whereIn('propina_id', array_keys($deB))->count());
        $deA = $this->propinasDe($matriculaA);

        $this->alterarPreco($planoB, '2028-02', 2_000_000, self::APLICAR);

        $this->assertSame($deA, $this->propinasDe($matriculaA), 'A segunda operação (ano B) não toca no ano A.');
        $this->assertSame(2, AlteracaoPrecoPlano::query()->count());
        $this->assertSame([$planoA->ano_lectivo_id, $planoB->ano_lectivo_id], AlteracaoPrecoPlano::query()->orderBy('id')->pluck('ano_lectivo_id')->all());
        $this->assertPropinasCoerentes();
    }

    public function test_2_ano_anterior_com_divida_em_aberto_nao_e_tocado_em_aplicar_nem_em_manter(): void
    {
        ['planoA' => $planoA, 'planoB' => $planoB, 'matriculaA' => $matriculaA] = $this->cenarioDoisAnos();
        $dividaDoAnoAnterior = $this->propinasDe($matriculaA);

        $this->alterarPreco($planoB, '2027-09', 3_000_000, self::APLICAR);
        $this->assertSame($dividaDoAnoAnterior, $this->propinasDe($matriculaA));

        $this->alterarPreco($planoB->fresh(), '2027-09', 3_500_000, self::MANTER);
        $this->assertSame($dividaDoAnoAnterior, $this->propinasDe($matriculaA));
        $this->assertSame([9, 6, 2_500_000], [$planoA->fresh()->mes_inicio, $planoA->fresh()->mes_fim, $planoA->fresh()->valor->unidadesMenores()]);
    }

    public function test_3_ano_futuro_com_plano_de_alvos_iguais_nao_e_tocado_nem_colide(): void
    {
        ['planoA' => $planoA, 'planoB' => $planoB] = $this->cenarioDoisAnos();
        $planoBAntes = $planoB->fresh()->getAttributes();

        $alteracao = $this->alterarPreco($planoA, '2027-02', 3_000_000, self::MANTER);

        $this->assertSame($planoBAntes, $planoB->fresh()->getAttributes());
        $this->assertSame($planoA->ano_lectivo_id, $alteracao->planoNovo->ano_lectivo_id);
        // O nome "Propina Mensal (desde 02/2027)" é único no ano A; o plano "Propina Mensal" de B não conta.
        $this->assertSame('Propina Mensal (desde 02/2027)', $alteracao->planoNovo->nome);
        $this->assertSame(2, PlanoPropina::query()->where('ano_lectivo_id', $planoA->ano_lectivo_id)->count());
        $this->assertSame(1, PlanoPropina::query()->where('ano_lectivo_id', $planoB->ano_lectivo_id)->count());
    }

    public function test_5_propina_de_outro_ano_injectada_aborta_a_confirmacao_inteira(): void
    {
        ['planoA' => $planoA, 'matriculaB' => $matriculaB] = $this->cenarioDoisAnos();
        $impressao = $this->previa($planoA, '2027-02', 3_000_000, self::APLICAR)->impressaoDigital;
        // A intrusa aparece DEPOIS da pré-visualização: só a verificação dentro da transacção a apanha.
        $intrusa = Propina::query()->where('matricula_id', $matriculaB->id)->where('ordem', 7)->firstOrFail();
        DB::table('propinas')->where('id', $intrusa->id)->update(['plano_propina_id' => $planoA->id]);
        $antes = $this->fotografia();

        $this->assertRecusa('plano', 'outro ano lectivo', fn () => app(AlterarPrecoPlanoAction::class)->executar(
            $planoA->fresh(),
            $this->pedidoAlteracao('2027-02', 3_000_000, self::APLICAR, 'Motivo', $impressao),
        ));
        $this->assertSame($antes, $this->fotografia(), 'Plano, propinas, alterações e ajustes inalterados.');
    }

    public function test_8_o_plano_novo_herda_o_ano_do_plano_alterado(): void
    {
        ['planoA' => $planoA, 'planoB' => $planoB] = $this->cenarioDoisAnos();

        $this->assertSame($planoA->ano_lectivo_id, $this->alterarPreco($planoA, '2027-02', 3_000_000, self::APLICAR)->planoNovo->ano_lectivo_id);
        $this->assertSame($planoB->ano_lectivo_id, $this->alterarPreco($planoB, '2028-01', 3_000_000, self::APLICAR)->planoNovo->ano_lectivo_id);
    }
}
