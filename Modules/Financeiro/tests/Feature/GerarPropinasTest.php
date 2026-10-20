<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Enums\Estado;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
use Modules\Financeiro\DTO\ResultadoGeracaoPropinasDTO;
use Modules\Financeiro\Enums\AmbitoGeracaoPropinas;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Enums\Periodicidade;
use Modules\Financeiro\Models\CambioPlataforma;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Services\GerarPropinasService;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\TaxaCambio;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Turma\Models\Turma;
use Mockery;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class GerarPropinasTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-09-01 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $extras
     */
    private function gerar(Turma $turma, array $extras = []): ResultadoGeracaoPropinasDTO
    {
        return app(GerarPropinasService::class)->gerar(new PedidoGeracaoPropinasDTO(...array_merge([
            'ano_lectivo_id' => (int) $turma->ano_lectivo_id,
            'ambito' => AmbitoGeracaoPropinas::TURMA,
            'origem' => OrigemGeracaoPropina::MANUAL,
            'alvo_id' => (int) $turma->id,
        ], $extras)));
    }

    public function test_grava_as_propinas_com_o_snapshot_completo(): void
    {
        ['ano' => $ano, 'turma' => $turma, 'plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

        $resultado = $this->gerar($turma);

        $this->assertFalse($resultado->toArray()['previsualizacao']);
        $this->assertSame(10, $resultado->totais()['criadas']);
        $propinas = Propina::query()->where('matricula_id', $matricula->id)->orderBy('periodo_inicio')->get();
        $this->assertCount(10, $propinas);
        $primeira = $propinas->first();
        $this->assertSame($plano->id, $primeira->plano_propina_id);
        $this->assertSame($ano->id, $primeira->ano_lectivo_id);
        $this->assertSame(1, $primeira->ordem);
        $this->assertSame('2026-09-01', $primeira->periodo_inicio->toDateString());
        $this->assertSame('2026-09-30', $primeira->periodo_fim->toDateString());
        $this->assertSame(2_500_000, $primeira->valor->unidadesMenores());
        $this->assertSame(2_500_000, $primeira->valor_original->unidadesMenores());
        $this->assertSame(0, $primeira->valor_pago->unidadesMenores());
        $this->assertSame(EstadoCobranca::EM_ABERTO, $primeira->estado);
        $this->assertSame('AOA', $primeira->moeda);
        $this->assertNull($primeira->cambio_usd);
        $this->assertSame('2026-09-10', $primeira->data_vencimento->toDateString());
        $this->assertSame(5, $primeira->dias_tolerancia);
        $this->assertSame('2026-09-15', $primeira->data_limite->toDateString());
        $this->assertSame(OrigemGeracaoPropina::MANUAL, $primeira->origem_geracao);
        $this->assertNull($primeira->motivo_geracao);
        $this->assertNull($primeira->criado_por);
        $this->assertSame(
            $propinas->pluck('id')->all(),
            array_column($resultado->toArray()['matriculas'][0]['criadas'], 'id'),
        );
        $this->assertPropinasCoerentes();
    }

    public function test_grava_o_cambio_do_dia_da_escola(): void
    {
        CambioPlataforma::create([
            'moeda_cotada' => 'AOA', 'moeda_base' => 'USD', 'data' => '2026-08-31',
            'taxa' => TaxaCambio::deDecimal('910')->micros(), 'fonte' => 'manual',
        ]);
        ['turma' => $turma] = $this->cenarioPropinas();

        $this->gerar($turma);

        $this->assertSame([910_000_000], Propina::query()->pluck('cambio_usd')->unique()->values()->all());
    }

    public function test_regista_o_autor_quando_ha_utilizador_autenticado(): void
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $admin = $this->adminEscola();
        ['turma' => $turma] = $this->cenarioPropinas();

        $this->actingAs($admin);
        $this->gerar($turma);

        $this->assertSame([$admin->id], Propina::query()->pluck('criado_por')->unique()->values()->all());
    }

    public function test_e_idempotente_a_segunda_execucao_nao_cria_nada(): void
    {
        ['turma' => $turma] = $this->cenarioPropinas();
        $this->gerar($turma);

        $segunda = $this->gerar($turma);

        $this->assertSame(0, $segunda->totais()['criadas']);
        $this->assertSame(10, $segunda->totais()['ignoradas']);
        $this->assertSame(10, Propina::query()->count());
        $this->assertSame(
            'Já existe a propina de Set/2026 (plano «Propina Mensal»): Em Aberto.',
            $segunda->toArray()['matriculas'][0]['ignoradas'][0]['motivo'],
        );
    }

    public function test_uma_mensal_existente_cobre_o_trimestre_que_a_contem(): void
    {
        ['ano' => $ano, 'turma' => $turma, 'plano' => $mensal, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->propina($matricula, $mensal, 2); // Out/2026 já cobrado pelo plano mensal
        $mensal->update(['estado' => Estado::INATIVO->value]);
        $this->plano($ano, 'Trimestral', ['periodicidade' => Periodicidade::TRIMESTRAL, 'intervalo_meses' => 3]);

        $linha = $this->gerar($turma)->toArray()['matriculas'][0];

        $this->assertSame(['Dez/2026–Fev/2027', 'Mar–Mai/2027', 'Jun/2027'], array_column($linha['criadas'], 'periodo'));
        $this->assertSame('Set–Nov/2026', $linha['ignoradas'][0]['periodo']);
        $this->assertStringContainsString('Já existe a propina de Out/2026', $linha['ignoradas'][0]['motivo']);
        $this->assertPropinasCoerentes();
    }

    public function test_um_trimestre_existente_cobre_os_meses_dele(): void
    {
        ['ano' => $ano, 'turma' => $turma, 'plano' => $trimestral, 'matricula' => $matricula] = $this->cenarioPropinas([
            'periodicidade' => Periodicidade::TRIMESTRAL,
            'intervalo_meses' => 3,
        ]);
        $this->propina($matricula, $trimestral, 1); // Set–Nov/2026
        $trimestral->update(['estado' => Estado::INATIVO->value]);
        $this->plano($ano, 'Mensal');

        $linha = $this->gerar($turma)->toArray()['matriculas'][0];

        $this->assertSame(['Set/2026', 'Out/2026', 'Nov/2026'], array_column($linha['ignoradas'], 'periodo'));
        $this->assertCount(7, $linha['criadas']);
        $this->assertSame('Dez/2026', $linha['criadas'][0]['periodo']);
    }

    public function test_depois_de_cancelar_o_mesmo_periodo_volta_a_gerar(): void
    {
        ['turma' => $turma] = $this->cenarioPropinas();
        $this->gerar($turma);
        $this->comEstado(Propina::query()->where('periodo_inicio', '2026-10-01')->firstOrFail(), EstadoCobranca::CANCELADA);

        $segunda = $this->gerar($turma);

        $this->assertSame(['Out/2026'], array_column($segunda->toArray()['matriculas'][0]['criadas'], 'periodo'));
        $this->assertSame(11, Propina::query()->count());
        $this->assertSame(10, Propina::query()->activas()->count());
    }

    public function test_o_vencimento_e_a_tolerancia_vem_das_regras_de_cobranca(): void
    {
        RegraCobranca::doTenant()->update(['dia_vencimento' => 20, 'dias_tolerancia' => 3]);
        ['turma' => $turma] = $this->cenarioPropinas();

        $this->gerar($turma);

        $janeiro = Propina::query()->where('periodo_inicio', '2027-01-01')->firstOrFail();
        $this->assertSame('2027-01-20', $janeiro->data_vencimento->toDateString());
        $this->assertSame(3, $janeiro->dias_tolerancia);
        $this->assertSame('2027-01-23', $janeiro->data_limite->toDateString());
    }

    public function test_a_geracao_retroactiva_fica_em_atraso_de_imediato_com_origem_e_motivo(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-12-01 10:00:00', 'UTC'));
        ['turma' => $turma] = $this->cenarioPropinas();
        $tardia = $this->matriculaActiva($turma, '2026-11-15');
        $motivo = 'Regularização pedida pela direcção: Setembro e Outubro por cobrar.';

        $this->gerar($turma, [
            'ambito' => AmbitoGeracaoPropinas::MATRICULA,
            'alvo_id' => null,
            'matricula_numero' => $tardia->numero_registo_matricula,
            'incluir_periodos_anteriores' => true,
            'motivo_geracao' => $motivo,
        ]);

        $hoje = app(RelogioDoTenant::class)->hoje();
        $setembro = Propina::query()->where('matricula_id', $tardia->id)->where('periodo_inicio', '2026-09-01')->firstOrFail();
        $novembro = Propina::query()->where('matricula_id', $tardia->id)->where('periodo_inicio', '2026-11-01')->firstOrFail();
        $dezembro = Propina::query()->where('matricula_id', $tardia->id)->where('periodo_inicio', '2026-12-01')->firstOrFail();

        $this->assertSame(OrigemGeracaoPropina::RETROACTIVA, $setembro->origem_geracao);
        $this->assertSame($motivo, $setembro->motivo_geracao);
        $this->assertSame(EstadoCobranca::EM_ATRASO, $setembro->estadoResolvido($hoje));
        $this->assertSame(OrigemGeracaoPropina::MANUAL, $novembro->origem_geracao);
        $this->assertNull($novembro->motivo_geracao);
        $this->assertSame(EstadoCobranca::EM_ATRASO, $novembro->estadoResolvido($hoje));
        $this->assertSame(EstadoCobranca::EM_ABERTO, $dezembro->estadoResolvido($hoje));
    }

    public function test_mudar_plano_regras_e_cambio_depois_nao_altera_as_propinas_existentes(): void
    {
        ['turma' => $turma, 'plano' => $plano] = $this->cenarioPropinas();
        $this->gerar($turma);
        $antes = Propina::query()->orderBy('id')->get()->map(fn (Propina $p) => $p->getAttributes())->all();

        $plano->forceFill(['valor' => Dinheiro::deUnidadesMenores(9_000_000)])->save();
        RegraCobranca::doTenant()->update(['dia_vencimento' => 25, 'dias_tolerancia' => 0]);
        CambioPlataforma::create([
            'moeda_cotada' => 'AOA', 'moeda_base' => 'USD', 'data' => '2026-08-31',
            'taxa' => TaxaCambio::deDecimal('950')->micros(), 'fonte' => 'manual',
        ]);

        $this->assertSame(0, $this->gerar($turma)->totais()['criadas']);
        $this->assertSame($antes, Propina::query()->orderBy('id')->get()->map(fn (Propina $p) => $p->getAttributes())->all());
    }

    public function test_uma_violacao_de_unicidade_reverte_so_a_matricula_que_falhou(): void
    {
        ['turma' => $turma, 'plano' => $plano, 'matricula' => $primeira] = $this->cenarioPropinas();
        $segunda = $this->matriculaActiva($turma);
        $concorrente = false;
        Propina::creating(function (Propina $propina) use ($plano, $primeira, &$concorrente) {
            // Simula outra geração que gravou Outubro desta matrícula entre a leitura e a escrita.
            if ($concorrente || $propina->matricula_id !== $primeira->id || $propina->ordem !== 2) {
                return;
            }

            $concorrente = true;
            $this->propina($primeira, $plano, 2);
        });

        $resultado = $this->gerar($turma);

        $this->assertSame(10, $resultado->totais()['criadas']);
        $this->assertSame(1, $resultado->totais()['erros']);
        $linha = collect($resultado->toArray()['matriculas'])->firstWhere('matricula_id', $primeira->id);
        $this->assertSame([], $linha['criadas']);
        $this->assertStringContainsString('ao mesmo tempo', $linha['erros'][0]);
        $this->assertSame(0, Propina::query()->where('matricula_id', $primeira->id)->count(), 'Setembro e a «concorrente» foram revertidas.');
        $this->assertSame(10, Propina::query()->where('matricula_id', $segunda->id)->count());

        $this->assertSame(10, $this->gerar($turma)->totais()['criadas'], 'A execução seguinte recupera a matrícula que falhou.');
        $this->assertSame(20, Propina::query()->count());
        $this->assertPropinasCoerentes();
    }

    public function test_outra_violacao_de_unicidade_nao_e_concorrencia_e_regista_a_mensagem_original(): void
    {
        ['turma' => $turma] = $this->cenarioPropinas();
        $pdo = new PDOException('UNIQUE constraint failed: matriculas.numero_registo_matricula');
        $pdo->errorInfo = ['23000', 19, $pdo->getMessage()];
        Propina::creating(function () use ($pdo) {
            throw new QueryException('sqlite', 'insert into x', [], $pdo);
        });
        Log::spy();
        $handler = Mockery::spy(ExceptionHandler::class);
        $this->app->instance(ExceptionHandler::class, $handler);

        $linha = collect($this->gerar($turma)->toArray()['matriculas'])->first();

        $this->assertStringContainsString('Erro inesperado', $linha['erros'][0]);
        $this->assertStringNotContainsString('ao mesmo tempo', $linha['erros'][0]);
        $handler->shouldHaveReceived('report')->atLeast()->once();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $c = []) => $m === 'financeiro.gerar-propinas.matricula-falhou'
            && $c['concorrencia'] === false
            && str_contains($c['mensagem'], 'matriculas.numero_registo_matricula'))->atLeast()->once();
    }

    public function test_o_dia_de_vencimento_corrompido_e_limitado_a_28(): void
    {
        RegraCobranca::doTenant();
        RegraCobranca::query()->update(['dia_vencimento' => 31]);
        ['turma' => $turma] = $this->cenarioPropinas();

        $this->gerar($turma);

        $this->assertSame(
            ['2027-02-28'],
            Propina::query()->where('periodo_inicio', '2027-02-01')->get()->map(fn (Propina $p) => $p->data_vencimento->toDateString())->all(),
        );
        $this->assertSame(0, Propina::query()->whereRaw("strftime('%d', data_vencimento) > '28'")->count());
    }

    public function test_um_erro_inesperado_numa_matricula_nao_impede_as_outras(): void
    {
        ['turma' => $turma, 'matricula' => $primeira] = $this->cenarioPropinas();
        $segunda = $this->matriculaActiva($turma);
        $falhou = false;
        Propina::creating(function (Propina $propina) use ($primeira, &$falhou) {
            if (! $falhou && $propina->matricula_id === $primeira->id && $propina->ordem === 3) {
                $falhou = true;

                throw new RuntimeException('Falha simulada.');
            }
        });

        $resultado = $this->gerar($turma);

        $linha = collect($resultado->toArray()['matriculas'])->firstWhere('matricula_id', $primeira->id);
        $this->assertStringContainsString('Erro inesperado', $linha['erros'][0]);
        $this->assertSame(0, Propina::query()->where('matricula_id', $primeira->id)->count());
        $this->assertSame(10, Propina::query()->where('matricula_id', $segunda->id)->count());
    }

    public function test_so_gera_no_tenant_corrente(): void
    {
        ['turma' => $turma] = $this->cenarioPropinas();
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, fn () => $this->cenarioPropinas());

        $this->gerar($turma);

        $this->assertSame(10, Propina::query()->count());
        $this->assertSame(0, $this->noTenant($outro, fn () => Propina::query()->count()));
    }

    public function test_a_pre_visualizacao_carrega_aluno_e_turma_sem_n_mais_1(): void
    {
        ['turma' => $turma] = $this->cenarioPropinas();
        $this->matriculaActiva($turma);
        $medir = function () use ($turma): int {
            $conta = 0;
            DB::listen(function ($consulta) use (&$conta) {
                if (preg_match('/from "(alunos|turmas|dados_pessoas)"/', $consulta->sql)) {
                    $conta++;
                }
            });
            app(GerarPropinasService::class)->preVisualizar(new PedidoGeracaoPropinasDTO(
                ano_lectivo_id: (int) $turma->ano_lectivo_id,
                ambito: AmbitoGeracaoPropinas::TURMA,
                origem: OrigemGeracaoPropina::MANUAL,
                alvo_id: (int) $turma->id,
            ));

            return $conta;
        };
        $medir(); // aquece configuração e caches
        $com2 = $medir();
        foreach (range(1, 5) as $_) {
            $this->matriculaActiva($turma);
        }

        $this->assertGreaterThan(0, $com2);
        $this->assertSame($com2, $medir(), 'O número de leituras de aluno/turma não cresce com as matrículas.');
    }
}
