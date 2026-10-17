<?php

namespace Modules\Financeiro\Tests\Concerns;

use Carbon\CarbonImmutable;
use Modules\Core\Enums\Estado;
use Modules\Financeiro\Actions\AnularPagamentoAction;
use Modules\Financeiro\Actions\AnularUtilizacaoCreditoAction;
use Modules\Financeiro\Actions\AplicarCreditoAction;
use Modules\Financeiro\Actions\RegistarPagamentoAction;
use Modules\Financeiro\DTO\AplicarCreditoDTO;
use Modules\Financeiro\DTO\LinhaDeDistribuicao;
use Modules\Financeiro\DTO\MotivoDeAnulacaoDTO;
use Modules\Financeiro\DTO\RegistarPagamentoDTO;
use Modules\Financeiro\Enums\EstadoCredito;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Enums\EstadoUtilizacaoCredito;
use Modules\Financeiro\Enums\TipoMetodoPagamento;
use Modules\Financeiro\Models\Credito;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Models\MetodoPagamento;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Models\PagamentoMulta;
use Modules\Financeiro\Models\PagamentoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaMulta;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Matricula\Models\Matricula;

/**
 * Métodos de pagamento e pagamentos para testes. Requer ComDadosAcademicosFinanceiro e ComPropinasFinanceiro.
 * `pagamentoDirecto` grava SEM a RegistarPagamentoAction (sem distribuição, crédito nem recálculo): só para
 * testar o model e as fontes. Os cenários de negócio usam `pagar()` (Task 7).
 */
trait ComPagamentosFinanceiro
{
    protected function metodoPagamento(string $nome = 'Numerário', bool $activo = true): MetodoPagamento
    {
        return MetodoPagamento::query()->firstOrCreate(
            ['nome' => $nome],
            ['tipo' => TipoMetodoPagamento::NUMERARIO, 'estado' => $activo ? Estado::ATIVO->value : Estado::INATIVO->value],
        );
    }

    /**
     * @param  array<string, mixed>  $atributos  sobrepõe qualquer coluna (numero, data_pagamento, referencia…)
     */
    protected function pagamentoDirecto(Matricula $matricula, int $valor = 2_500_000, array $atributos = []): Pagamento
    {
        $pagamento = (new Pagamento())->forceFill(array_merge([
            'matricula_id' => $matricula->id,
            'aluno_id' => $matricula->aluno_id,
            'metodo_pagamento_id' => $this->metodoPagamento()->id,
            'numero' => sprintf('2026-%04d', Pagamento::query()->count() + 1),
            'valor' => $valor,
            'moeda' => app(MoedaDoTenant::class)->atual()->codigo,
            'cambio_usd' => null,
            'data_pagamento' => '2026-10-16',
        ], $atributos));
        $pagamento->save();

        return $pagamento->refresh();
    }

    protected function distribuicaoDirecta(Pagamento $pagamento, Propina $propina, int $valor): PagamentoPropina
    {
        return PagamentoPropina::create(['pagamento_id' => $pagamento->id, 'propina_id' => $propina->id, 'valor' => $valor]);
    }

    protected function creditoDirecto(Pagamento $origem, int $valor): Credito
    {
        return Credito::create(['aluno_id' => $origem->aluno_id, 'origem_pagamento_id' => $origem->id, 'valor' => $valor])->refresh();
    }

    protected function utilizacaoDirecta(Credito $credito, Propina $propina, int $valor, string $data = '2026-10-16', ?Pagamento $combinadoCom = null): CreditoUtilizacao
    {
        return CreditoUtilizacao::create([
            'credito_id' => $credito->id, 'propina_id' => $propina->id, 'valor' => $valor, 'data_utilizacao' => $data,
            'pagamento_id' => $combinadoCom?->id,
        ])->refresh();
    }

    protected function regras(bool $parcial = false, bool $antecipado = false): RegraCobranca
    {
        $regras = RegraCobranca::doTenant();
        $regras->update(['permite_pagamento_parcial' => $parcial, 'permite_pagamento_antecipado' => $antecipado]);

        return $regras->refresh();
    }

    /**
     * @param  list<array{0: Propina|int, 1: int}>  $pares
     * @return list<LinhaDeDistribuicao>
     */
    protected function linhas(array $pares): array
    {
        return array_map(
            fn (array $par) => new LinhaDeDistribuicao($par[0] instanceof Propina ? (int) $par[0]->id : $par[0], Dinheiro::deUnidadesMenores($par[1])),
            $pares,
        );
    }

    /**
     * @param  list<array{0: Propina|int, 1: int}>|null  $linhas  null = distribuição automática
     */
    protected function pedidoPagamento(int $valor, ?array $linhas = null, int $credito = 0, string $data = '2026-10-16', ?string $referencia = null, ?int $metodoId = null): RegistarPagamentoDTO
    {
        return new RegistarPagamentoDTO(
            metodoPagamentoId: $metodoId ?? (int) $this->metodoPagamento()->id,
            valor: Dinheiro::deUnidadesMenores($valor),
            dataPagamento: CarbonImmutable::createFromFormat('!Y-m-d', $data),
            referencia: $referencia,
            distribuicao: $linhas === null ? null : $this->linhas($linhas),
            valorCredito: Dinheiro::deUnidadesMenores($credito),
        );
    }

    /**
     * @param  list<array{0: Propina|int, 1: int}>|null  $linhas
     */
    protected function pedidoCredito(int $credito, ?array $linhas = null): AplicarCreditoDTO
    {
        return new AplicarCreditoDTO(Dinheiro::deUnidadesMenores($credito), $linhas === null ? null : $this->linhas($linhas));
    }

    /**
     * Regista pela Action real (distribuição, crédito, recálculo).
     *
     * @param  list<array{0: Propina|int, 1: int}>|null  $linhas  null = automática
     */
    protected function pagar(Matricula $matricula, int $valor, ?array $linhas = null, int $credito = 0, string $data = '2026-10-16', ?string $referencia = null): Pagamento
    {
        return app(RegistarPagamentoAction::class)->executar($matricula->fresh(), $this->pedidoPagamento($valor, $linhas, $credito, $data, $referencia));
    }

    /**
     * Reconciliação global de Pagamentos-A (critério 8.2/1 do plano de fases), sobre TODO o tenant corrente:
     * - pagamento Confirmado: valor = Σ distribuições + crédito gerado; Anulado: o crédito gerado (se existe) está Anulado;
     * - crédito: valor_utilizado = Σ utilizações Activas, estado coerente, aluno = aluno do pagamento de origem;
     * - propina: valor_pago = Σ distribuições de pagamentos Confirmados + Σ utilizações Activas.
     */
    protected function assertPagamentosCoerentes(): void
    {
        foreach (Pagamento::query()->with(['distribuicoes', 'multas', 'credito'])->orderBy('id')->get() as $pagamento) {
            $id = "pagamento {$pagamento->numero}";
            $distribuido = $pagamento->distribuicoes->sum(fn ($d) => $d->valor->unidadesMenores())
                + $pagamento->multas->sum(fn ($d) => $d->valor->unidadesMenores());
            $gerado = $pagamento->credito?->valor->unidadesMenores() ?? 0;

            $this->assertSame($pagamento->valor->unidadesMenores(), $distribuido + $gerado, "{$id}: valor = Σ distribuições + multas + crédito gerado");

            if ($pagamento->estado === EstadoPagamento::ANULADO && $pagamento->credito !== null) {
                $this->assertSame(EstadoCredito::ANULADO, $pagamento->credito->estado, "{$id}: o crédito de um pagamento anulado está anulado");
            }
        }

        foreach (Credito::query()->with(['utilizacoes', 'origem'])->orderBy('id')->get() as $credito) {
            $activas = $credito->utilizacoes->where('estado', EstadoUtilizacaoCredito::ACTIVA)->sum(fn ($u) => $u->valor->unidadesMenores());

            $this->assertSame($activas, $credito->valor_utilizado->unidadesMenores(), "crédito {$credito->id}: valor_utilizado");
            $this->assertSame((int) $credito->origem->aluno_id, (int) $credito->aluno_id, "crédito {$credito->id}: aluno");

            foreach ($credito->utilizacoes->whereNotNull('pagamento_id') as $u) {
                $this->assertSame((int) $credito->aluno_id, (int) Pagamento::query()->findOrFail($u->pagamento_id)->aluno_id, "utilização {$u->id}: o pagamento combinado é do aluno do crédito (O8)");
            }
        }

        foreach (Propina::query()->orderBy('id')->get() as $propina) {
            $pago = PagamentoPropina::query()->where('propina_id', $propina->id)
                ->whereHas('pagamento', fn ($p) => $p->where('estado', EstadoPagamento::CONFIRMADO->value))->get()
                ->sum(fn ($d) => $d->valor->unidadesMenores())
                + CreditoUtilizacao::query()->where('propina_id', $propina->id)->where('estado', EstadoUtilizacaoCredito::ACTIVA->value)->get()
                    ->sum(fn ($u) => $u->valor->unidadesMenores());

            $this->assertSame($pago, $propina->valor_pago->unidadesMenores(), "propina {$propina->id}: valor_pago = Σ pagamentos confirmados + créditos activos");
        }

        foreach (PropinaMulta::query()->orderBy('id')->get() as $multa) {
            $id = "multa {$multa->id}";
            $pago = PagamentoMulta::query()->where('propina_multa_id', $multa->id)
                ->whereHas('pagamento', fn ($p) => $p->where('estado', EstadoPagamento::CONFIRMADO->value))->get()
                ->sum(fn ($d) => $d->valor->unidadesMenores())
                + CreditoUtilizacao::query()->where('propina_multa_id', $multa->id)->where('estado', EstadoUtilizacaoCredito::ACTIVA->value)->get()
                    ->sum(fn ($u) => $u->valor->unidadesMenores());

            $this->assertSame($pago, $multa->valor_pago->unidadesMenores(), "{$id}: valor_pago = Σ pagamentos confirmados + créditos activos");
            $this->assertLessThanOrEqual($multa->valor->unidadesMenores(), $multa->valor_pago->unidadesMenores(), "{$id}: valor_pago <= valor");
        }

        $this->assertPropinasCoerentes();
    }

    protected function anularPagamento(Pagamento $pagamento, string $motivo = 'Registado por engano'): Pagamento
    {
        return app(AnularPagamentoAction::class)->executar($pagamento->fresh(), new MotivoDeAnulacaoDTO($motivo), auth()->id());
    }

    protected function anularUtilizacao(CreditoUtilizacao $utilizacao, string $motivo = 'Aplicado por engano'): CreditoUtilizacao
    {
        return app(AnularUtilizacaoCreditoAction::class)->executar($utilizacao->fresh(), new MotivoDeAnulacaoDTO($motivo), auth()->id());
    }

    /** Aplica crédito do aluno a UMA propina (AplicarCreditoAction real) e devolve a utilização criada. */
    protected function aplicarCredito(Propina $propina, int $valor): CreditoUtilizacao
    {
        return app(AplicarCreditoAction::class)->executar($propina->matricula()->firstOrFail(), $this->pedidoCredito($valor, [[$propina, $valor]]))[0];
    }
}
