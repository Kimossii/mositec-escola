<?php

namespace Modules\Financeiro\Tests\Feature\Pgsql;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Actions\AlterarPrecoPlanoAction;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Models\AlteracaoPrecoPlano;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaAjuste;
use Modules\Financeiro\Tests\Concerns\ComAlteracaoPreco;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\PgsqlConcorrenciaTestCase;

/**
 * D7/D8/D9/D13 de F4 com sessões PostgreSQL reais: a alteração de preço segura matrículas → plano → propinas até
 * ao commit; a geração e o cancelamento concorrentes esperam (sem deadlock, nem em stress); duas alterações
 * simultâneas do mesmo plano => uma vence, a outra é recusada («dados mudaram»); uma falha a meio reverte tudo
 * de verdade (nada confirmado, visto de outra ligação). SQLite ignora FOR UPDATE.
 */
#[Group('pgsql')]
class AlteracaoPrecoConcorrenciaPgsqlTest extends PgsqlConcorrenciaTestCase
{
    use ComAlteracaoPreco;
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use ComProcessosConcorrentes;

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

    /** SQLSTATE da falha de uma consulta na outra ligação; null se correu (55P03 = esperou mais do que o lock_timeout). */
    private function sqlstateNaOutraLigacao(string $sql, array $valores): ?string
    {
        try {
            $this->outraLigacao()->select($sql, $valores);

            return null;
        } catch (QueryException $e) {
            return $e->errorInfo[0] ?? (string) $e->getCode();
        }
    }

    /** @return array{0: PlanoPropina, 1: Propina, 2: int} plano com Fev–Jun em aberto de uma matrícula; a propina de Fev; o id da matrícula */
    private function cenario(): array
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

        foreach (range(6, 10) as $ordem) {
            $this->propina($matricula, $plano, $ordem);
        }

        return [$plano, Propina::query()->where('matricula_id', $matricula->id)->where('ordem', 6)->firstOrFail(), (int) $matricula->id];
    }

    /**
     * Um ano lectivo próprio com 3 matrículas na mesma turma: A (Fev–Jun), B (Set–Jun, uma já paga) e C (sem propinas,
     * a geração cria-as). As três operações sobrepõem-se nas matrículas A e B.
     *
     * @return array{ano: mixed, turma: mixed, plano: PlanoPropina, a: int, b: int, c: int}
     */
    private function rodada(int $n): array
    {
        $ano = $this->anoLectivo("2026/2027 r{$n}");
        $turma = $this->turma($ano, $this->nivel('N' . uniqid()));
        $plano = $this->plano($ano, 'Propina Mensal');
        $a = $this->matriculaActiva($turma);
        $b = $this->matriculaActiva($turma);
        $c = $this->matriculaActiva($turma);

        foreach (range(6, 10) as $ordem) {
            $this->propina($a, $plano, $ordem);
        }

        foreach (range(1, 10) as $ordem) {
            $propina = $this->propina($b, $plano, $ordem);

            if ($ordem === 3) {
                $this->comEstado($propina, EstadoCobranca::PAGA);
            }
        }

        return ['ano' => $ano, 'turma' => $turma, 'plano' => $plano, 'a' => (int) $a->id, 'b' => (int) $b->id, 'c' => (int) $c->id];
    }

    private function dadosAlterar(PlanoPropina $plano, string $impressao, int $valor = 3_000_000): array
    {
        return ['plano' => $plano->id, 'mes' => '2027-02', 'valor' => $valor, 'modo' => ModoAlteracaoPreco::APLICAR_GERADAS->value, 'motivo' => 'Actualização anual', 'impressao' => $impressao];
    }

    /** Invariante auditável vista da outra ligação: valor = valor_original + Σ(ajustes), e os CHECK de estado. */
    private function assertInvariantesNaOutraLigacao(): void
    {
        $divergentes = $this->outraLigacao()->select(
            'select p.id from propinas p where p.valor <> p.valor_original + coalesce((select sum(a.valor_novo - a.valor_anterior) from propina_ajustes a where a.propina_id = p.id), 0)',
        );
        $this->assertSame([], $divergentes, 'valor = valor_original + Σ(ajustes) em todas as propinas.');
        $this->assertSame(0, (int) $this->outraLigacao()->selectOne('select count(*) as n from propinas where plano_propina_id is null')->n);
    }

    private function marca(string $tabela): string
    {
        return (string) $this->outraLigacao()->selectOne("select coalesce(md5(string_agg(t::text, '|' order by id)), '') as m from {$tabela} t")->m;
    }

    /** Estado inteiro (visto de outra ligação) do que F4 pode tocar. */
    private function marcaDeTudo(): array
    {
        return array_map(fn (string $tabela) => $this->marca($tabela), ['propinas', 'planos_propina', 'plano_propina_alvos', 'alteracoes_preco_plano', 'propina_ajustes']);
    }

    public function test_segura_matricula_plano_e_propinas_ate_ao_commit(): void
    {
        [$plano, $fevereiro, $matriculaId] = $this->cenario();
        $verificado = [];
        AlteracaoPrecoPlano::creating(function () use ($plano, $fevereiro, $matriculaId, &$verificado) {
            $verificado = [
                'matricula' => $this->sqlstateNaOutraLigacao('select id from matriculas where id = ? for key share', [$matriculaId]),
                'plano_partilhado' => $this->sqlstateNaOutraLigacao('select id from planos_propina where id = ? for share', [$plano->id]),
                'plano_exclusivo' => $this->sqlstateNaOutraLigacao('select id from planos_propina where id = ? for update', [$plano->id]),
                'propina' => $this->sqlstateNaOutraLigacao('select id from propinas where id = ? for update', [$fevereiro->id]),
            ];
        });

        $this->alterarPreco($plano, '2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS);

        $this->assertSame('55P03', $verificado['matricula'], 'Inserir propinas desta matrícula (KEY SHARE) ou mudar-lhe o estado tem de esperar.');
        $this->assertSame('55P03', $verificado['plano_partilhado'], 'A geração (FOR SHARE nos planos, L15) tem de esperar pela divisão.');
        $this->assertSame('55P03', $verificado['plano_exclusivo'], 'Outra alteração do mesmo plano tem de esperar.');
        $this->assertSame('55P03', $verificado['propina'], 'Um pagamento (FOR UPDATE na propina) tem de esperar.');
        $this->assertNull($this->sqlstateNaOutraLigacao('select id from planos_propina where id = ? for update', [$plano->id]), 'Depois do commit tudo é libertado.');
        $this->assertSame(3_000_000, (int) $this->outraLigacao()->selectOne('select valor from propinas where id = ?', [$fevereiro->id])->valor);
    }

    public function test_pagamento_confirmado_por_outra_sessao_depois_da_previsualizacao_faz_recusar(): void
    {
        [$plano, $fevereiro] = $this->cenario();
        $impressao = $this->previa($plano, '2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS)->impressaoDigital;
        // Outra sessão (ex.: Pagamentos) regista 1.000,00 e confirma — coerente com os CHECK.
        $this->outraLigacao()->update('update propinas set valor_pago = 100000, estado = 2, estado_descricao = ? where id = ?', ['Parcialmente Paga', $fevereiro->id]);

        try {
            app(AlterarPrecoPlanoAction::class)->executar($plano->fresh(), $this->pedidoAlteracao('2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS, 'Motivo', $impressao));
            $this->fail('Devia recusar.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('impressao_digital', $e->errors());
        }

        $this->assertSame(0, (int) $this->outraLigacao()->selectOne('select count(*) as n from alteracoes_preco_plano')->n);
        $this->assertSame(2_500_000, (int) $this->outraLigacao()->selectOne('select valor from propinas where id = ?', [$fevereiro->id])->valor);
    }

    public function test_duplo_clique_sequencial_grava_uma_so_vez(): void
    {
        [$plano] = $this->cenario();
        $impressao = $this->previa($plano, '2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS)->impressaoDigital;
        $pedido = $this->pedidoAlteracao('2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS, 'Motivo', $impressao);

        app(AlterarPrecoPlanoAction::class)->executar($plano->fresh(), $pedido);

        try {
            app(AlterarPrecoPlanoAction::class)->executar($plano->fresh(), $pedido);
            $this->fail('O 2.º clique devia ser recusado.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('impressao_digital', $e->errors());
        }

        $this->assertSame(1, (int) $this->outraLigacao()->selectOne('select count(*) as n from alteracoes_preco_plano')->n);
        $this->assertSame(2, (int) $this->outraLigacao()->selectOne('select count(*) as n from planos_propina')->n);
    }

    public function test_a_alteracao_espera_por_uma_sessao_que_segura_a_matricula_e_continua_ao_libertar(): void
    {
        [$plano, , $matriculaId] = $this->cenario();
        $impressao = $this->previa($plano, '2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS)->impressaoDigital;
        $sessao = $this->outraLigacao();
        $sessao->beginTransaction();
        $sessao->select('select id from matriculas where id = ? for update', [$matriculaId]);

        $filho = $this->lancar('alterar', $this->dadosAlterar($plano, $impressao));
        $this->assertTrue($this->esperarQueOFilhoFiqueABloquear($filho), 'A alteração tem de ficar à espera da matrícula (1.º bloqueio).');
        $sessao->commit();
        $resposta = $this->esperar($filho);

        $this->assertTrue($resposta['ok'], json_encode($resposta));
        $this->assertSame(1, (int) $this->outraLigacao()->selectOne('select count(*) as n from alteracoes_preco_plano')->n);
        $this->assertInvariantesNaOutraLigacao();
    }

    public function test_a_geracao_concorrente_espera_pela_divisao_e_depois_gera_ao_preco_novo(): void
    {
        $r = $this->rodada(1);
        $impressao = $this->previa($r['plano'], '2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS)->impressaoDigital;
        $estado = ['filho' => null, 'ficouPreso' => null, 'aindaAcorrer' => null];
        AlteracaoPrecoPlano::creating(function () use ($r, &$estado) {
            $estado['filho'] = $this->lancar('gerar', ['ano' => $r['ano']->id, 'turma' => $r['turma']->id]);
            $estado['ficouPreso'] = $this->esperarQueOFilhoFiqueABloquear($estado['filho']);
            usleep(700_000);
            $estado['aindaAcorrer'] = $this->aCorrer($estado['filho']);
        });

        app(AlterarPrecoPlanoAction::class)->executar($r['plano']->fresh(), $this->pedidoAlteracao('2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS, 'Actualização anual', $impressao, true));
        $this->assertTrue($estado['ficouPreso'], 'A geração tem de esperar pelos bloqueios da divisão.');
        $this->assertTrue($estado['aindaAcorrer'], 'E continuar à espera até ao commit.');
        $resposta = $this->esperar($estado['filho']);

        $this->assertTrue($resposta['ok'], json_encode($resposta));
        $this->assertSame(0, $resposta['totais']['erros'], json_encode($resposta));
        $this->assertSame(10, (int) $this->outraLigacao()->selectOne('select count(*) as n from propinas where matricula_id = ? and estado <> 4', [$r['c']])->n);
        $this->assertSame(
            [3_000_000],
            array_map(fn ($l) => (int) $l->valor, $this->outraLigacao()->select('select distinct valor from propinas where matricula_id = ? and periodo_inicio >= ?', [$r['c'], '2027-02-01'])),
            'A matrícula gerada depois da divisão nasce ao preço novo a partir do mês de efeito.',
        );
        $this->assertSame(
            [2_500_000],
            array_map(fn ($l) => (int) $l->valor, $this->outraLigacao()->select('select distinct valor from propinas where matricula_id = ? and periodo_inicio < ?', [$r['c'], '2027-02-01'])),
        );
        $this->assertInvariantesNaOutraLigacao();
    }

    public function test_o_cancelamento_concorrente_espera_pela_alteracao_e_depois_cancela(): void
    {
        $r = $this->rodada(1);
        $impressao = $this->previa($r['plano'], '2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS)->impressaoDigital;
        $estado = ['filho' => null, 'ficouPreso' => null, 'aindaAcorrer' => null];
        AlteracaoPrecoPlano::creating(function () use ($r, &$estado) {
            $estado['filho'] = $this->lancar('cancelar', ['matricula' => $r['b']]);
            $estado['ficouPreso'] = $this->esperarQueOFilhoFiqueABloquear($estado['filho']);
            usleep(700_000);
            $estado['aindaAcorrer'] = $this->aCorrer($estado['filho']);
        });

        app(AlterarPrecoPlanoAction::class)->executar($r['plano']->fresh(), $this->pedidoAlteracao('2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS, 'Actualização anual', $impressao, true));
        $this->assertTrue($estado['ficouPreso'], 'O cancelamento (FOR UPDATE da matrícula) tem de esperar.');
        $this->assertTrue($estado['aindaAcorrer']);
        $resposta = $this->esperar($estado['filho']);

        $this->assertTrue($resposta['ok'], json_encode($resposta));
        $this->assertSame(
            0,
            (int) $this->outraLigacao()->selectOne('select count(*) as n from propinas where matricula_id = ? and periodo_inicio > ? and estado not in (3, 4)', [$r['b'], '2026-10-01'])->n,
            'Depois da alteração, o cancelamento apanhou as propinas (já reassociadas) da matrícula.',
        );
        $this->assertInvariantesNaOutraLigacao();
    }

    /** O stress: alteração + geração + cancelamento ao mesmo tempo, vários arranques; nunca deadlock nem erro inesperado. */
    public function test_alteracao_geracao_e_cancelamento_em_simultaneo_nunca_dao_deadlock(): void
    {
        $deadlocksAntes = $this->deadlocksDaBase();
        $vitoriasDaAlteracao = 0;

        foreach (range(1, 6) as $n) {
            $r = $this->rodada($n);
            $impressao = $this->previa($r['plano'], '2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS)->impressaoDigital;
            $partida = microtime(true) + 2.5;
            // Ordem de arranque varia por rodada: a corrida muda de sítio.
            $operacoes = [
                'alterar' => ['alterar', $this->dadosAlterar($r['plano'], $impressao)],
                'gerar' => ['gerar', ['ano' => $r['ano']->id, 'turma' => $r['turma']->id]],
                'cancelar' => ['cancelar', ['matricula' => $r['b']]],
            ];
            $ordem = array_keys($operacoes);

            if ($n % 2 === 0) {
                $ordem = array_reverse($ordem);
            }

            if ($n % 3 === 0) {
                $ordem = [$ordem[1], $ordem[2], $ordem[0]];
            }

            $filhos = [];

            // Atrasos de 0–60 ms entre as operações, diferentes em cada rodada: umas vezes a alteração chega primeiro
            // (vence), outras chega depois (é recusada por «dados mudaram»).
            $atrasos = [
                1 => ['alterar' => 0, 'gerar' => 40, 'cancelar' => 60],
                2 => ['alterar' => 0, 'gerar' => 10, 'cancelar' => 25],
                3 => ['alterar' => 60, 'gerar' => 0, 'cancelar' => 30],
                4 => ['alterar' => 15, 'gerar' => 0, 'cancelar' => 0],
                5 => ['alterar' => 0, 'gerar' => 0, 'cancelar' => 0],
                6 => ['alterar' => 30, 'gerar' => 60, 'cancelar' => 0],
            ][$n];

            foreach ($ordem as $nome) {
                $filhos[$nome] = $this->lancar($operacoes[$nome][0], $operacoes[$nome][1], $partida, $atrasos[$nome]);
            }

            $respostas = [];

            foreach ($filhos as $nome => &$filho) {
                $respostas[$nome] = $this->esperar($filho);
            }

            unset($filho);

            $this->assertTrue($respostas['gerar']['ok'], "r{$n} gerar: " . json_encode($respostas['gerar']));
            $this->assertSame(0, $respostas['gerar']['totais']['erros'], "r{$n} gerar: " . json_encode($respostas['gerar']));
            $this->assertTrue($respostas['cancelar']['ok'], "r{$n} cancelar: " . json_encode($respostas['cancelar']));

            if ($respostas['alterar']['ok']) {
                $vitoriasDaAlteracao++;
            } else {
                $this->assertSame('validacao', $respostas['alterar']['tipo'], "r{$n} alterar: " . json_encode($respostas['alterar']));
                $this->assertSame(['impressao_digital'], $respostas['alterar']['campos'], "r{$n} alterar: " . json_encode($respostas['alterar']));
                $this->assertSame(AlterarPrecoPlanoAction::MENSAGEM_DESACTUALIZADA, $respostas['alterar']['mensagem']);
            }

            $this->assertInvariantesNaOutraLigacao();
        }

        usleep(1_800_000); // as estatísticas do servidor são publicadas com atraso
        $this->assertSame($deadlocksAntes, $this->deadlocksDaBase(), 'O servidor não detectou nenhum deadlock.');
        fwrite(STDERR, "\n[stress] alterações confirmadas: {$vitoriasDaAlteracao}/6 (as restantes recusadas por «dados mudaram»)\n");
    }

    /** Duas pessoas confirmam ao mesmo tempo a mesma pré-visualização (e outra com valor diferente): uma vence. */
    public function test_duas_alteracoes_simultaneas_do_mesmo_plano_uma_vence_a_outra_e_recusada(): void
    {
        foreach (range(1, 4) as $n) {
            $r = $this->rodada($n);
            $impressao = $this->previa($r['plano'], '2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS)->impressaoDigital;
            $impressaoDiferente = $this->previa($r['plano'], '2027-02', 3_500_000, ModoAlteracaoPreco::APLICAR_GERADAS)->impressaoDigital;
            $partida = microtime(true) + 2.5;
            $primeiro = $this->lancar('alterar', $this->dadosAlterar($r['plano'], $impressao), $partida);
            // n par: a 2.ª pessoa pede um valor diferente (outra pré-visualização, mesmo plano).
            $segundo = $this->lancar('alterar', $n % 2 === 0 ? $this->dadosAlterar($r['plano'], $impressaoDiferente, 3_500_000) : $this->dadosAlterar($r['plano'], $impressao), $partida);
            $respostas = [$this->esperar($primeiro), $this->esperar($segundo)];

            $vencedoras = array_values(array_filter($respostas, fn ($x) => $x['ok']));
            $recusadas = array_values(array_filter($respostas, fn ($x) => ! $x['ok']));
            $this->assertCount(1, $vencedoras, "r{$n}: " . json_encode($respostas));
            $this->assertCount(1, $recusadas, "r{$n}: " . json_encode($respostas));
            $this->assertSame('validacao', $recusadas[0]['tipo'], json_encode($recusadas[0]));
            $this->assertSame(['impressao_digital'], $recusadas[0]['campos']);
            $this->assertSame(AlterarPrecoPlanoAction::MENSAGEM_DESACTUALIZADA, $recusadas[0]['mensagem']);

            $this->assertSame(1, (int) $this->outraLigacao()->selectOne('select count(*) as n from alteracoes_preco_plano where ano_lectivo_id = ?', [$r['ano']->id])->n, 'Um só registo.');
            $this->assertSame(2, (int) $this->outraLigacao()->selectOne('select count(*) as n from planos_propina where ano_lectivo_id = ?', [$r['ano']->id])->n, 'Um só plano novo.');
            $this->assertInvariantesNaOutraLigacao();
        }
    }

    public static function pontosDeFalha(): array
    {
        return [
            'na 2.ª propina reajustada' => ['propina', 2],
            'no 3.º ajuste' => ['ajuste', 3],
            'no registo da alteração' => ['alteracao', 1],
            'na criação do plano novo' => ['plano', 1],
        ];
    }

    #[DataProvider('pontosDeFalha')]
    public function test_falha_a_meio_reverte_de_verdade_nada_fica_confirmado_e_a_ligacao_continua_utilizavel(string $ponto, int $vez): void
    {
        [$plano] = $this->cenario();
        $impressao = $this->previa($plano, '2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS)->impressaoDigital;
        $antes = $this->marcaDeTudo();
        $activa = true;
        $vezes = 0;
        $falhar = function () use (&$activa, &$vezes, $vez, $ponto) {
            if ($activa && ++$vezes === $vez) {
                throw new RuntimeException("Falha injectada: {$ponto}");
            }
        };

        match ($ponto) {
            'propina' => Propina::updating(fn (Propina $p) => $p->valor_pago->unidadesMenores() === 0 && $p->isDirty('valor') ? $falhar() : null),
            'ajuste' => PropinaAjuste::creating($falhar),
            'alteracao' => AlteracaoPrecoPlano::creating($falhar),
            'plano' => PlanoPropina::creating($falhar),
        };

        try {
            app(AlterarPrecoPlanoAction::class)->executar($plano->fresh(), $this->pedidoAlteracao('2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS, 'Actualização anual', $impressao, true));
            $this->fail('A falha devia propagar.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Falha injectada', $e->getMessage());
        }

        $this->assertSame($antes, $this->marcaDeTudo(), 'Nada ficou confirmado (visto de outra sessão).');
        $this->assertNull($this->sqlstateNaOutraLigacao('select id from planos_propina where id = ? for update', [$plano->id]), 'Os bloqueios foram libertados.');
        $this->assertSame(0, \DB::transactionLevel(), 'A ligação voltou ao nível 0, sem transacção abortada.');

        // A ligação continua utilizável e a mesma confirmação, agora sem falha, passa.
        $activa = false;
        $alteracao = app(AlterarPrecoPlanoAction::class)->executar($plano->fresh(), $this->pedidoAlteracao('2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS, 'Actualização anual', $impressao, true));
        $this->assertSame(5, $alteracao->total_propinas_afectadas);
        $this->assertSame(1, (int) $this->outraLigacao()->selectOne('select count(*) as n from alteracoes_preco_plano')->n);
        $this->assertInvariantesNaOutraLigacao();
    }

    public function test_falha_a_meio_com_uma_propina_paga_e_uma_parcial_reverte_tudo(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $fonte = $this->fontePagamentoFalsa();

        foreach (range(6, 10) as $ordem) {
            $propina = $this->propina($matricula, $plano, $ordem);

            if ($ordem === 7) {
                $parcial = $this->comEstado($propina, EstadoCobranca::PARCIALMENTE_PAGA, 2_000_000);
                $fonte->definir($parcial, [[2_000_000, '2026-10-05']]);
            }
        }

        $impressao = $this->previa($plano, '2027-02', 1_500_000, ModoAlteracaoPreco::APLICAR_GERADAS)->impressaoDigital;
        $antes = $this->marcaDeTudo();
        PropinaAjuste::creating(function () {
            throw new RuntimeException('Falha injectada: último ajuste');
        });

        try {
            app(AlterarPrecoPlanoAction::class)->executar($plano->fresh(), $this->pedidoAlteracao('2027-02', 1_500_000, ModoAlteracaoPreco::APLICAR_GERADAS, 'Actualização anual', $impressao, true));
            $this->fail('A falha devia propagar.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame($antes, $this->marcaDeTudo(), 'A parcial reajustada para Paga (UPDATE sob os CHECK) também foi revertida.');
        $this->assertSame(0, (int) $this->outraLigacao()->selectOne('select count(*) as n from propinas where estado = 3')->n);
    }
}
