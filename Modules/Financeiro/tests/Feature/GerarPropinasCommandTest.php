<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\PendingCommand;
use Modules\Core\Tenancy\TenantContext;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use RuntimeException;
use Tests\TestCase;

class GerarPropinasCommandTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
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

    private function ligar(): void
    {
        RegraCobranca::doTenant()->update(['gerar_automaticamente' => true]);
    }

    private function correr(array $opcoes = []): PendingCommand
    {
        return $this->artisan('financeiro:gerar-propinas', $opcoes ?: ['--tenant' => $this->tenant->codigo]);
    }

    public function test_com_a_geracao_automatica_desligada_nao_faz_nada(): void
    {
        $this->cenarioPropinas();

        $this->correr()->expectsOutputToContain('geração automática desligada')->assertSuccessful();

        $this->assertSame(0, Propina::query()->count());
    }

    public function test_ligada_gera_as_propinas_em_falta_com_origem_comando_e_regista_no_log(): void
    {
        Log::spy();
        $this->ligar();
        ['matricula' => $matricula] = $this->cenarioPropinas();

        $this->correr()->assertSuccessful();

        $this->assertSame(10, Propina::query()->where('matricula_id', $matricula->id)->count());
        $this->assertTrue(Propina::query()->get()->every(fn (Propina $p) => $p->origem_geracao === OrigemGeracaoPropina::COMANDO));
        $this->assertTrue(Propina::query()->get()->every(fn (Propina $p) => $p->criado_por === null));
        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $mensagem, array $contexto = []) => str_ends_with($mensagem, 'financeiro.gerar-propinas')
                && ($contexto['tenant'] ?? null) === $this->tenant->codigo
                && ($contexto['criadas'] ?? null) === 10
                && ($contexto['erros'] ?? null) === 0)
            ->once();
    }

    public function test_todos_os_tenants_cada_um_com_a_sua_regra(): void
    {
        $this->ligar();
        $this->cenarioPropinas();
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, fn () => $this->cenarioPropinas()); // a escola B fica com a geração desligada

        $this->correr(['--todos' => true])->assertSuccessful();

        $this->assertSame(10, Propina::query()->count());
        $this->assertSame(0, $this->noTenant($outro, fn () => Propina::query()->count()));
    }

    public function test_matriculas_activas_de_dois_anos_lectivos_geram_cada_uma_pelo_seu_plano(): void
    {
        $this->ligar();
        ['matricula' => $actual] = $this->cenarioPropinas();
        $anterior = $this->anoLectivo('2025/2026', '2025-09-01', '2026-07-31');
        $this->plano($anterior, 'Propina 2025/2026', ['valor' => Dinheiro::deUnidadesMenores(2_000_000)]);
        $antiga = $this->matriculaActiva($this->turma($anterior, $this->nivel('NA')), '2025-09-01');

        $this->correr()->assertSuccessful();

        $this->assertSame(10, Propina::query()->where('matricula_id', $actual->id)->count());
        $this->assertSame(
            [2_000_000],
            Propina::query()->where('matricula_id', $antiga->id)->get()->map(fn (Propina $p) => $p->valor->unidadesMenores())->unique()->values()->all(),
        );
    }

    public function test_uma_falha_fica_registada_e_a_execucao_seguinte_recupera_sem_duplicar(): void
    {
        // Critério 3 de §8.2: matrícula Activa que ficou sem propinas (falha depois da activação) → o comando gera-as.
        $this->ligar();
        ['turma' => $turma, 'matricula' => $primeira] = $this->cenarioPropinas();
        $segunda = $this->matriculaActiva($turma);
        $falhou = false;
        Propina::creating(function () use (&$falhou) {
            if (! $falhou) {
                $falhou = true;

                throw new RuntimeException('Falha simulada na primeira propina.');
            }
        });

        $this->correr()->assertFailed();
        $this->assertSame(0, Propina::query()->where('matricula_id', $primeira->id)->count());
        $this->assertSame(10, Propina::query()->where('matricula_id', $segunda->id)->count());

        $this->correr()->assertSuccessful();
        $this->assertSame(20, Propina::query()->count());

        $this->correr()->assertSuccessful();
        $this->assertSame(20, Propina::query()->count());
    }

    public function test_sem_opcao_ou_com_as_duas_falha_sem_gerar_nada(): void
    {
        $this->ligar();
        $this->cenarioPropinas();

        $this->artisan('financeiro:gerar-propinas')->assertExitCode(1);
        $this->artisan('financeiro:gerar-propinas', ['--tenant' => $this->tenant->codigo, '--todos' => true])->assertExitCode(1);

        $this->assertSame(0, Propina::query()->count());
    }

    public function test_a_falha_de_um_tenant_nao_impede_os_outros(): void
    {
        $this->ligar();
        $this->cenarioPropinas();
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, function () {
            $this->ligar();
            $this->cenarioPropinas();
        });
        $falhouNoPrimeiro = false;
        Propina::creating(function (Propina $propina) use (&$falhouNoPrimeiro) {
            // Todas as matrículas da primeira escola a gerar falham; a outra tem de gerar na mesma.
            if (app(TenantContext::class)->id() === $this->tenant->id) {
                $falhouNoPrimeiro = true;

                throw new RuntimeException('Falha simulada na escola A.');
            }
        });

        $this->correr(['--todos' => true])->assertFailed();

        $this->assertTrue($falhouNoPrimeiro);
        $this->assertSame(0, Propina::query()->count());
        $this->assertSame(10, $this->noTenant($outro, fn () => Propina::query()->count()));
    }

    public function test_esta_agendado_diariamente_para_todos_os_tenants_sem_sobreposicao(): void
    {
        $eventos = collect(app(Schedule::class)->events())
            ->filter(fn ($evento) => str_contains((string) $evento->command, 'financeiro:gerar-propinas'));

        $this->assertCount(1, $eventos);
        $this->assertStringContainsString('--todos', $eventos->first()->command);
        $this->assertSame('0 2 * * *', $eventos->first()->expression);
        $this->assertTrue($eventos->first()->withoutOverlapping);
    }
}
