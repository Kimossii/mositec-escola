<?php

namespace Modules\Financeiro\Tests\Feature\Pgsql;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Modules\Financeiro\Actions\AlterarPrecoPlanoAction;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Models\AlteracaoPrecoPlano;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Tests\Concerns\ComAlteracaoPreco;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\Turma;
use PHPUnit\Framework\Attributes\Group;
use Tests\PgsqlConcorrenciaTestCase;

/**
 * Pagamentos-A com sessões PostgreSQL reais (processos filhos): duplo pagamento, parciais simultâneos, pagamento ×
 * alteração de preço × cancelamento, numeração e referência. SQLite ignora FOR UPDATE.
 */
#[Group('pgsql')]
class PagamentoConcorrenciaPgsqlTest extends PgsqlConcorrenciaTestCase
{
    use ComAlteracaoPreco;
    use ComDadosAcademicosFinanceiro;
    use ComPagamentosFinanceiro;
    use ComProcessosConcorrentes;
    use ComPropinasFinanceiro;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array{0: Matricula, 1: PlanoPropina, 2: array<int, Propina>} matrícula com Set…Jun, todas Em Aberto */
    private function cenario(): array
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propinas = [];

        foreach (range(1, 10) as $ordem) {
            $propinas[$ordem] = $this->propina($matricula, $plano, $ordem);
        }

        return [$matricula, $plano, $propinas];
    }

    private function dadosPagar(Matricula $matricula, int $valor, ?array $linhas, ?string $referencia = null): array
    {
        return ['metodo' => $this->metodoPagamento()->id, 'matricula' => $matricula->id, 'valor' => $valor, 'linhas' => $linhas, 'referencia' => $referencia];
    }

    /** @return list<array<string, mixed>> respostas dos filhos lançados ao mesmo tempo */
    private function emSimultaneo(array $operacoes): array
    {
        $partida = microtime(true) + 2.5;
        $filhos = array_map(fn (array $op) => $this->lancar($op[0], $op[1], $partida), $operacoes);

        // Espera por TODOS (mesmo que um falhe), para nenhum filho sobreviver ao teste e colidir com o TRUNCATE do seguinte.
        $respostas = [];
        $erro = null;

        foreach ($filhos as $i => $filho) {
            try {
                $respostas[$i] = $this->esperar($filho);
            } catch (\Throwable $e) {
                $erro ??= $e;
            }
        }

        if ($erro !== null) {
            throw $erro;
        }

        return $respostas;
    }

    private function sqlstateNaOutraLigacao(string $sql, array $valores): ?string
    {
        try {
            $this->outraLigacao()->select($sql, $valores);

            return null;
        } catch (QueryException $e) {
            return $e->errorInfo[0] ?? (string) $e->getCode();
        }
    }

    private function assertCoerenteNaOutraLigacao(): void
    {
        $divergentes = $this->outraLigacao()->select(
            "select p.id from propinas p where p.valor_pago <> coalesce((select sum(pp.valor) from pagamento_propinas pp join pagamentos g on g.id = pp.pagamento_id where pp.propina_id = p.id and g.estado = 1), 0)
             + coalesce((select sum(u.valor) from credito_utilizacoes u where u.propina_id = p.id and u.estado = 1), 0)",
        );
        $this->assertSame([], $divergentes, 'valor_pago = Σ pagamentos confirmados + créditos activos em todas as propinas.');
        $this->assertSame([], $this->outraLigacao()->select(
            'select g.id from pagamentos g where g.estado = 1 and g.valor <> coalesce((select sum(pp.valor) from pagamento_propinas pp where pp.pagamento_id = g.id), 0) + coalesce((select c.valor from creditos c where c.origem_pagamento_id = g.id), 0)',
        ), 'valor = Σ distribuições + crédito gerado.');
    }

    public function test_dois_pagamentos_simultaneos_da_mesma_propina_so_um_passa(): void
    {
        [$matricula, , $p] = $this->cenario();
        $this->regras();
        $antes = $this->deadlocksDaBase();

        $respostas = $this->emSimultaneo([
            ['pagar', $this->dadosPagar($matricula, 2_500_000, [[$p[1]->id, 2_500_000]])],
            ['pagar', $this->dadosPagar($matricula, 2_500_000, [[$p[1]->id, 2_500_000]])],
        ]);

        $this->assertSame(1, count(array_filter($respostas, fn ($r) => $r['ok'])), json_encode($respostas));
        $recusada = array_values(array_filter($respostas, fn ($r) => ! $r['ok']))[0];
        $this->assertSame(['validacao', ['distribuicao.0.propina_id']], [$recusada['tipo'], $recusada['campos']]);
        $this->assertSame(2_500_000, (int) $this->outraLigacao()->selectOne('select valor_pago from propinas where id = ?', [$p[1]->id])->valor_pago);
        $this->assertSame(1, (int) $this->outraLigacao()->selectOne('select count(*) as n from pagamentos')->n);
        $this->assertSame($antes, $this->deadlocksDaBase());
        $this->assertCoerenteNaOutraLigacao();
    }

    public function test_dois_parciais_simultaneos_nunca_excedem_o_saldo(): void
    {
        [$matricula, , $p] = $this->cenario();
        $this->regras(parcial: true);

        $respostas = $this->emSimultaneo([
            ['pagar', $this->dadosPagar($matricula, 2_000_000, [[$p[1]->id, 2_000_000]])],
            ['pagar', $this->dadosPagar($matricula, 2_000_000, [[$p[1]->id, 2_000_000]])],
        ]);

        $this->assertSame(1, count(array_filter($respostas, fn ($r) => $r['ok'])), json_encode($respostas));
        $this->assertSame(2_000_000, (int) $this->outraLigacao()->selectOne('select valor_pago from propinas where id = ?', [$p[1]->id])->valor_pago);
        $this->assertCoerenteNaOutraLigacao();
    }

    public function test_o_registo_segura_matricula_propinas_e_sequencia_ate_ao_commit(): void
    {
        [$matricula, , $p] = $this->cenario();
        $this->regras();
        // A linha da sequência só é visível (e bloqueável) por outra sessão depois do 1.º commit: um pagamento antes.
        $this->pagar($matricula, 2_500_000, [[$p[2], 2_500_000]]);
        $verificado = [];
        Pagamento::created(function () use ($matricula, $p, &$verificado) {
            $verificado = [
                'matricula' => $this->sqlstateNaOutraLigacao('select id from matriculas where id = ? for update', [$matricula->id]),
                'propina' => $this->sqlstateNaOutraLigacao('select id from propinas where id = ? for update', [$p[1]->id]),
                'sequencia' => $this->sqlstateNaOutraLigacao('select id from pagamento_sequencias where tenant_id = ? for update', [$this->tenant->id]),
                'plano' => $this->sqlstateNaOutraLigacao('select id from planos_propina where id = ? for update', [$p[1]->plano_propina_id]),
            ];
        });

        $this->pagar($matricula, 2_500_000, [[$p[1], 2_500_000]]);

        $this->assertSame(['matricula' => '55P03', 'propina' => '55P03', 'sequencia' => '55P03', 'plano' => null], $verificado, 'D13: matrícula, propinas e sequência presas; o plano nunca.');
        $this->assertNull($this->sqlstateNaOutraLigacao('select id from matriculas where id = ? for update', [$matricula->id]), 'Libertado no commit.');
    }

    public function test_pagamento_durante_uma_alteracao_de_preco_espera_e_depois_paga_ao_preco_novo(): void
    {
        [$matricula, $plano, $p] = $this->cenario();
        $this->regras(parcial: true, antecipado: true);
        $impressao = $this->previa($plano, '2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS)->impressaoDigital;
        $estado = ['filho' => null, 'ficouPreso' => null];
        $antes = $this->deadlocksDaBase();
        AlteracaoPrecoPlano::creating(function () use ($matricula, $p, &$estado) {
            $estado['filho'] = $this->lancar('pagar', $this->dadosPagar($matricula, 1_000_000, [[$p[6]->id, 1_000_000]]));
            $estado['ficouPreso'] = $this->esperarQueOFilhoFiqueABloquear($estado['filho']);
        });

        app(AlterarPrecoPlanoAction::class)->executar($plano->fresh(), $this->pedidoAlteracao('2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS, 'Actualização anual', $impressao));
        $resposta = $this->esperar($estado['filho']);

        $this->assertTrue($estado['ficouPreso'], 'O pagamento (FOR UPDATE da matrícula) espera pela alteração.');
        $this->assertTrue($resposta['ok'], json_encode($resposta));
        $linha = $this->outraLigacao()->selectOne('select valor, valor_pago, estado from propinas where id = ?', [$p[6]->id]);
        $this->assertSame([3_000_000, 1_000_000, 2], [(int) $linha->valor, (int) $linha->valor_pago, (int) $linha->estado]);
        $this->assertSame($antes, $this->deadlocksDaBase());
        $this->assertCoerenteNaOutraLigacao();
    }

    public function test_alteracao_de_preco_durante_um_pagamento_espera_e_recusa_por_impressao(): void
    {
        [$matricula, $plano, $p] = $this->cenario();
        $this->regras(parcial: true, antecipado: true);
        $impressao = $this->previa($plano, '2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS)->impressaoDigital;
        $estado = ['filho' => null, 'ficouPreso' => null];
        Pagamento::created(function () use ($plano, $impressao, &$estado) {
            $estado['filho'] = $this->lancar('alterar', [
                'plano' => $plano->id, 'mes' => '2027-02', 'valor' => 3_000_000, 'modo' => ModoAlteracaoPreco::APLICAR_GERADAS->value,
                'motivo' => 'Actualização anual', 'impressao' => $impressao,
            ]);
            $estado['ficouPreso'] = $this->esperarQueOFilhoFiqueABloquear($estado['filho']);
        });

        $this->pagar($matricula, 1_000_000, [[$p[6], 1_000_000]]);
        $resposta = $this->esperar($estado['filho']);

        $this->assertTrue($estado['ficouPreso'], 'A alteração (FOR UPDATE das matrículas) espera pelo pagamento.');
        $this->assertSame(['validacao', ['impressao_digital']], [$resposta['tipo'] ?? null, $resposta['campos'] ?? null], json_encode($resposta));
        $this->assertSame(0, (int) $this->outraLigacao()->selectOne('select count(*) as n from alteracoes_preco_plano')->n);
        $this->assertCoerenteNaOutraLigacao();
    }

    public function test_pagamento_e_cancelamento_manual_simultaneos_so_um_vence(): void
    {
        [$matricula, , $p] = $this->cenario();
        $this->regras();

        $respostas = $this->emSimultaneo([
            ['pagar', $this->dadosPagar($matricula, 2_500_000, [[$p[2]->id, 2_500_000]])],
            ['cancelar-propina', ['propina' => $p[2]->id]],
        ]);

        $this->assertSame(1, count(array_filter($respostas, fn ($r) => $r['ok'])), json_encode($respostas));
        $linha = $this->outraLigacao()->selectOne('select estado from propinas where id = ?', [$p[2]->id]);
        $pagamentos = (int) $this->outraLigacao()->selectOne('select count(*) as n from pagamentos')->n;
        $this->assertContains([(int) $linha->estado, $pagamentos], [[4, 0], [3, 1]], 'Ou Cancelada sem pagamento, ou Paga com um pagamento.');
        $this->assertCoerenteNaOutraLigacao();
    }

    public function test_numeracao_sem_lacunas_com_pagamentos_simultaneos(): void
    {
        $this->regras();
        $matriculas = [];
        ['turma' => $turma] = $this->cenarioPropinas();

        foreach (range(1, 4) as $i) {
            $matriculas[] = $this->matriculaActiva($turma);
        }

        $respostas = $this->emSimultaneo(array_map(fn (Matricula $m) => ['pagar', $this->dadosPagar($m, 100_000, null)], $matriculas));

        $this->assertSame([true, true, true, true], array_column($respostas, 'ok'), json_encode($respostas));
        $numeros = array_column($respostas, 'numero');
        sort($numeros);
        $this->assertSame(['2026-0001', '2026-0002', '2026-0003', '2026-0004'], $numeros);
    }

    public function test_mesma_referencia_em_simultaneo_uma_passa_e_a_outra_recebe_422(): void
    {
        $this->regras();
        ['turma' => $turma] = $this->cenarioPropinas();
        [$a, $b] = [$this->matriculaActiva($turma), $this->matriculaActiva($turma)];

        $respostas = $this->emSimultaneo([
            ['pagar', $this->dadosPagar($a, 100_000, null, 'TRF-777')],
            ['pagar', $this->dadosPagar($b, 100_000, null, 'TRF-777')],
        ]);

        $this->assertSame(1, count(array_filter($respostas, fn ($r) => $r['ok'])), json_encode($respostas));
        $recusada = array_values(array_filter($respostas, fn ($r) => ! $r['ok']))[0];
        $this->assertSame(['validacao', ['referencia']], [$recusada['tipo'], $recusada['campos']], 'Violação do índice convertida em 422 FORA da transacção.');
    }

    public function test_anular_pagamento_e_usar_o_seu_credito_em_simultaneo_nunca_os_dois(): void
    {
        [$matricula, , $p] = $this->cenario();
        $this->regras(parcial: true);
        $origem = $this->pagar($matricula, 3_000_000, [[$p[1], 2_500_000]]); // 500.000 de crédito

        $respostas = $this->emSimultaneo([
            ['anular-pagamento', ['pagamento' => $origem->id]],
            ['aplicar-credito', ['matricula' => $matricula->id, 'credito' => 500_000]],
        ]);

        $this->assertSame(1, count(array_filter($respostas, fn ($r) => $r['ok'])), json_encode($respostas));
        $this->assertCoerenteNaOutraLigacao();
    }

    public function test_pagamento_e_anulacao_da_propina_simultaneos_so_um_vence(): void
    {
        [$matricula, , $p] = $this->cenario();
        $this->regras(parcial: true);
        // A anulação exige que a propina já tenha recebido pagamentos e que já não tenha nenhum activo.
        $this->anularPagamento($this->pagar($matricula, 500_000, [[$p[2], 500_000]]));
        $antes = $this->deadlocksDaBase();

        $respostas = $this->emSimultaneo([
            ['pagar', $this->dadosPagar($matricula, 2_000_000, [[$p[2]->id, 2_000_000]])],
            ['anular-propina', ['propina' => $p[2]->id]],
        ]);

        $this->assertSame(1, count(array_filter($respostas, fn ($r) => $r['ok'])), json_encode($respostas));
        $linha = $this->outraLigacao()->selectOne('select estado from propinas where id = ?', [$p[2]->id]);
        $pagamentos = (int) $this->outraLigacao()->selectOne('select count(*) as n from pagamentos where estado = 1')->n;
        $this->assertContains([(int) $linha->estado, $pagamentos], [[5, 0], [2, 1]], 'Ou Anulada (só o pagamento já anulado antes), ou Parcialmente Paga com o novo pagamento: json ' . json_encode($respostas) . ' estado ' . $linha->estado);
        $this->assertSame($antes, $this->deadlocksDaBase());
        $this->assertCoerenteNaOutraLigacao();
    }

    public function test_stress_de_operacoes_mistas_sem_deadlocks(): void
    {
        [$matricula, , $p] = $this->cenario();
        $outra = $this->matriculaActiva(Turma::query()->firstOrFail());
        $this->regras(parcial: true, antecipado: true);
        $antes = $this->deadlocksDaBase();
        $existentes = [];
        $this->pagar($matricula, 3_000_000, [[$p[6], 2_500_000]]); // 500.000 de crédito para o aplicar-credito

        foreach ([4, 5] as $o) {
            $existentes[$o] = $this->pagar($matricula, 500_000, [[$p[$o], 500_000]]);
        }

        $ok = 0;

        foreach (range(1, 3) as $ronda) {
            $operacoes = [
                ['pagar', $this->dadosPagar($matricula, 300_000, [[$p[1]->id, 300_000]])],
                ['pagar', $this->dadosPagar($matricula, 300_000, [[$p[1]->id, 300_000]])],
                ['pagar', $this->dadosPagar($matricula, 400_000, [[$p[2]->id, 200_000], [$p[3]->id, 200_000]])],
                ['pagar', $this->dadosPagar($matricula, 400_000, [[$p[3]->id, 200_000], [$p[2]->id, 200_000]])],
                ['pagar', $this->dadosPagar($matricula, 100_000, null)],
                ['pagar', $this->dadosPagar($outra, 100_000, null)],
                ['aplicar-credito', ['matricula' => $matricula->id, 'credito' => 100_000]],
                ['cancelar-propina', ['propina' => $p[10]->id]],
            ];

            if ($ronda === 1) {
                $operacoes[] = ['anular-pagamento', ['pagamento' => $existentes[4]->id]];
                $operacoes[] = ['anular-pagamento', ['pagamento' => $existentes[5]->id]];
            }

            foreach ($this->emSimultaneo($operacoes) as $r) {
                $ok += $r['ok'] ? 1 : 0;
                $this->assertNotSame('Illuminate\\Database\\QueryException', $r['tipo'] ?? null, json_encode($r));
                $this->assertNotSame('40P01', $r['sqlstate'] ?? null, 'deadlock: ' . json_encode($r));
                $this->assertArrayNotHasKey('sqlstate', $r, json_encode($r));
            }

            $this->assertSame($antes, $this->deadlocksDaBase(), "Ronda {$ronda}: deadlocks do servidor.");
            $this->assertCoerenteNaOutraLigacao();
        }

        $this->assertGreaterThan(0, $ok);
        $this->assertSame($antes, $this->deadlocksDaBase());
    }
}
