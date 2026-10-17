<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Financeiro\Enums\TipoMulta;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaMulta;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComMultasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Tests\TestCase;

class AplicarMultasCommandTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComMultasFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

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

    private function umaPropina(): Propina
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

        return $this->propina($matricula, $plano);
    }

    private function correr(array $opcoes = [])
    {
        return $this->artisan('financeiro:aplicar-multas', $opcoes ?: ['--tenant' => $this->tenant->codigo]);
    }

    public function test_com_multa_activa_aplica_e_regista_no_log(): void
    {
        Log::spy();
        $this->multaActiva([[1, TipoMulta::PERCENTAGEM, 500]]);
        $this->umaPropina();

        $this->correr()->assertSuccessful();

        $this->assertSame(1, PropinaMulta::count());
        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $mensagem, array $contexto = []) => $mensagem === 'financeiro.aplicar-multas'
                && ($contexto['tenant'] ?? null) === $this->tenant->codigo
                && ($contexto['criadas'] ?? null) === 1)
            ->once();
    }

    public function test_idempotente_ao_correr_duas_vezes(): void
    {
        $this->multaActiva([[1, TipoMulta::PERCENTAGEM, 500]]);
        $this->umaPropina();

        $this->correr()->assertSuccessful();
        $this->correr()->assertSuccessful();

        $this->assertSame(1, PropinaMulta::count());
    }

    public function test_multas_desligadas_nao_faz_nada(): void
    {
        $this->umaPropina();

        $this->correr()->expectsOutputToContain('0 multa(s) criada(s)')->assertSuccessful();

        $this->assertSame(0, PropinaMulta::count());
    }

    public function test_sem_opcao_ou_com_as_duas_falha(): void
    {
        $this->multaActiva([[1, TipoMulta::PERCENTAGEM, 500]]);
        $this->umaPropina();

        $this->artisan('financeiro:aplicar-multas')->assertExitCode(1);
        $this->artisan('financeiro:aplicar-multas', ['--tenant' => $this->tenant->codigo, '--todos' => true])->assertExitCode(1);

        $this->assertSame(0, PropinaMulta::count());
    }

    public function test_todos_os_tenants_cada_um_com_a_sua_regra(): void
    {
        $this->multaActiva([[1, TipoMulta::PERCENTAGEM, 500]]);
        $this->umaPropina();

        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, function () {
            $this->umaPropina(); // sem multa_activa
        });

        $this->correr(['--todos' => true])->assertSuccessful();

        $this->assertSame(1, PropinaMulta::count());
        $this->assertSame(0, $this->noTenant($outro, fn () => PropinaMulta::count()));
    }

    public function test_esta_agendado_diariamente_sem_sobreposicao(): void
    {
        $eventos = collect(app(Schedule::class)->events())
            ->filter(fn ($evento) => str_contains((string) $evento->command, 'financeiro:aplicar-multas'));

        $this->assertCount(1, $eventos);
        $this->assertStringContainsString('--todos', $eventos->first()->command);
        $this->assertSame('30 2 * * *', $eventos->first()->expression);
        $this->assertTrue($eventos->first()->withoutOverlapping);
    }
}
