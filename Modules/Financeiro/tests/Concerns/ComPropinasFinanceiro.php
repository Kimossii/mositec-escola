<?php

namespace Modules\Financeiro\Tests\Concerns;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\Aluno\Models\Aluno;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Support\FontesDePagamentoDePropina;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\DadosPessoa;

/**
 * Alunos, matrículas e propinas para testes (F1 e fases seguintes). Requer ComDadosAcademicosFinanceiro
 * na mesma classe de teste. As propinas criadas aqui NÃO passam pela geração (F2): copiam o calendário
 * do plano e um snapshot fixo de teste (vencimento ao dia 10, tolerância de 5 dias → limite ao dia 15,
 * moeda da escola, sem câmbio, origem Manual).
 */
trait ComPropinasFinanceiro
{
    protected function alunoFinanceiro(string $nome = 'Aluno de Teste'): Aluno
    {
        $pessoa = DadosPessoa::create([
            'nome_completo' => $nome,
            'numero_identificacao' => 'BI-' . uniqid(),
            'tipo_pessoa' => DadosPessoa::TIPO_ALUNO,
        ]);

        return Aluno::create([
            'estabelecimento_id' => Estabelecimento::current()->id,
            'dados_pessoa_id' => $pessoa->id,
            'numero_matricula' => 'AL-' . uniqid(),
        ]);
    }

    protected function matriculaActiva(Turma $turma, string $dataMatricula = '2026-09-01', EstadoMatriculaEnum $estado = EstadoMatriculaEnum::ACTIVA): Matricula
    {
        return Matricula::create([
            'aluno_id' => $this->alunoFinanceiro()->id,
            'turma_id' => $turma->id,
            'ano_lectivo_id' => $turma->ano_lectivo_id,
            'numero_registo_matricula' => 'MR-' . uniqid(),
            'data_matricula' => $dataMatricula,
            'estado' => $estado->value,
        ]);
    }

    /**
     * Ano 2026/2027 (01/09/2026–31/07/2027), uma turma, o plano "Propina Mensal" (Set → Jun, 25.000,00 por
     * período, geral) e uma matrícula Activa de 01/09/2026. Uma chamada por tenant e por teste.
     *
     * @param  array<string, mixed>  $atributosPlano
     * @return array<string, mixed> chaves ano, turma, plano, matricula
     */
    protected function cenarioPropinas(array $atributosPlano = []): array
    {
        $ano = $this->anoLectivo();
        $turma = $this->turma($ano, $this->nivel('N' . uniqid()));
        $plano = $this->plano($ano, 'Propina Mensal', $atributosPlano);

        return ['ano' => $ano, 'turma' => $turma, 'plano' => $plano, 'matricula' => $this->matriculaActiva($turma)];
    }

    /**
     * @param  array<string, mixed>  $atributos  sobrepõe qualquer atributo preenchível (valor, períodos, origem…)
     */
    protected function propina(Matricula $matricula, PlanoPropina $plano, int $ordem = 1, array $atributos = []): Propina
    {
        $periodo = $plano->periodos()[$ordem - 1]
            ?? throw new InvalidArgumentException("O plano {$plano->nome} não tem o período {$ordem}.");
        $inicio = CarbonImmutable::parse($periodo['inicio']);

        return Propina::create(array_merge([
            'matricula_id' => $matricula->id,
            'plano_propina_id' => $plano->id,
            'ano_lectivo_id' => $plano->ano_lectivo_id,
            'ordem' => $ordem,
            'periodo_inicio' => $periodo['inicio'],
            'periodo_fim' => $periodo['fim'],
            'valor' => $plano->valor,
            'moeda' => app(MoedaDoTenant::class)->atual()->codigo,
            'cambio_usd' => null,
            'data_vencimento' => $inicio->setDay(10)->toDateString(),
            'dias_tolerancia' => 5,
            'origem_geracao' => OrigemGeracaoPropina::MANUAL,
        ], $atributos));
    }

    /**
     * Põe a propina num estado persistido SEM passar pelo RecalcularPropina (só para preparar cenários).
     * Paga usa valor_pago = valor e liquidação no início do período; Parcialmente Paga usa $valorPago
     * (por omissão metade); as restantes ficam com 0. Cancelada/Anulada recebem motivo e data.
     */
    protected function comEstado(Propina $propina, EstadoCobranca $estado, ?int $valorPago = null): Propina
    {
        $valor = $propina->valor->unidadesMenores();
        $pago = match ($estado) {
            EstadoCobranca::PAGA => $valor,
            EstadoCobranca::PARCIALMENTE_PAGA => $valorPago ?? intdiv($valor, 2),
            default => 0,
        };

        $propina->forceFill([
            'estado' => $estado,
            'valor_pago' => $pago,
            'capital_liquidado_em' => $estado === EstadoCobranca::PAGA ? $propina->periodo_inicio : null,
            'motivo_cancelamento' => $estado === EstadoCobranca::CANCELADA ? 'Cenário de teste' : null,
            'cancelado_em' => $estado === EstadoCobranca::CANCELADA ? now() : null,
            'motivo_anulacao' => $estado === EstadoCobranca::ANULADA ? 'Cenário de teste' : null,
            'anulado_em' => $estado === EstadoCobranca::ANULADA ? now() : null,
        ])->save();

        return $propina->refresh();
    }

    /**
     * Dez propinas mensais (Set/2026 … Jun/2027) de uma matrícula, com limites ao dia 15 de cada mês:
     * set_aberta, out_parcial (10.000,00), nov_paga, dez_cancelada, jan_anulada, fev_aberta,
     * mar_parcial (20.000,00), abr_paga, mai_aberta, jun_aberta.
     *
     * @return array<string, Propina>
     */
    protected function cenarioDeEstados(): array
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $nova = fn (int $ordem) => $this->propina($matricula, $plano, $ordem);

        return [
            'set_aberta' => $nova(1),
            'out_parcial' => $this->comEstado($nova(2), EstadoCobranca::PARCIALMENTE_PAGA, 1_000_000),
            'nov_paga' => $this->comEstado($nova(3), EstadoCobranca::PAGA),
            'dez_cancelada' => $this->comEstado($nova(4), EstadoCobranca::CANCELADA),
            'jan_anulada' => $this->comEstado($nova(5), EstadoCobranca::ANULADA),
            'fev_aberta' => $nova(6),
            'mar_parcial' => $this->comEstado($nova(7), EstadoCobranca::PARCIALMENTE_PAGA, 2_000_000),
            'abr_paga' => $this->comEstado($nova(8), EstadoCobranca::PAGA),
            'mai_aberta' => $nova(9),
            'jun_aberta' => $nova(10),
        ];
    }

    /**
     * Reconciliação global (critério 8.2/1 do plano de fases), sobre TODAS as propinas do tenant corrente.
     * F4 acrescenta Σ(ajustes) à igualdade valor = valor_original.
     */
    protected function assertPropinasCoerentes(): void
    {
        $moeda = app(MoedaDoTenant::class)->atual()->codigo;

        foreach (Propina::query()->orderBy('id')->get() as $propina) {
            $id = "propina {$propina->id}";
            $valor = $propina->valor->unidadesMenores();
            $pago = $propina->valor_pago->unidadesMenores();

            $this->assertGreaterThan(0, $valor, "{$id}: valor > 0");
            $this->assertGreaterThanOrEqual(0, $pago, "{$id}: valor_pago >= 0");
            $this->assertLessThanOrEqual($valor, $pago, "{$id}: valor_pago <= valor");
            $this->assertSame($propina->valor_original->unidadesMenores(), $valor, "{$id}: valor = valor_original (sem ajustes em F1)");

            $esperado = match (true) {
                in_array($propina->estado, [EstadoCobranca::CANCELADA, EstadoCobranca::ANULADA], true) => $propina->estado,
                $pago === 0 => EstadoCobranca::EM_ABERTO,
                $pago === $valor => EstadoCobranca::PAGA,
                default => EstadoCobranca::PARCIALMENTE_PAGA,
            };

            $this->assertSame($esperado, $propina->estado, "{$id}: estado coerente com o valor pago");
            $this->assertSame($propina->estado->label(), $propina->estado_descricao, "{$id}: estado_descricao");
            $this->assertSame($pago === $valor, $propina->capital_liquidado_em !== null, "{$id}: capital_liquidado_em só com saldo 0");
            $this->assertSame($moeda, $propina->moeda, "{$id}: moeda da escola");
            $this->assertSame(
                $propina->data_vencimento->addDays($propina->dias_tolerancia)->toDateString(),
                $propina->data_limite->toDateString(),
                "{$id}: data_limite = vencimento + tolerância",
            );
        }
    }

    /**
     * Regista uma fonte de pagamentos falsa (pode haver várias, com chaves diferentes).
     */
    protected function fontePagamentoFalsa(string $chave = 'fonte.pagamento.falsa'): FontePagamentoFalsa
    {
        $fonte = new FontePagamentoFalsa();
        $this->app->instance($chave, $fonte);
        $this->app->tag([$chave], FontesDePagamentoDePropina::ETIQUETA);

        return $fonte;
    }
}
