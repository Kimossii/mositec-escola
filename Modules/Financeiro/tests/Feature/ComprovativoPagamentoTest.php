<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Financeiro\Actions\AnexarComprovativoAction;
use Modules\Financeiro\Actions\RegistarPagamentoAction;
use Modules\Financeiro\DTO\RegistarPagamentoDTO;
use Modules\Financeiro\Enums\Periodicidade;
use Modules\Financeiro\Enums\TipoMetodoPagamento;
use Modules\Financeiro\Models\Credito;
use Modules\Financeiro\Models\MetodoPagamento;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Matricula\Models\Matricula;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use RuntimeException;
use Tests\TestCase;

class ComprovativoPagamentoTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPagamentosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    private Matricula $matricula;

    private Propina $set;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
        Storage::fake('privado');

        $ano = $this->anoLectivo();
        $turma = $this->turma($ano, $this->nivel('N' . uniqid()));
        $plano = PlanoPropina::create([
            'ano_lectivo_id' => $ano->id,
            'nome' => 'Propina Mensal',
            'periodicidade' => Periodicidade::MENSAL,
            'intervalo_meses' => 1,
            'valor' => Dinheiro::deUnidadesMenores(2_500_000),
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-06-30',
        ]);
        $this->matricula = $this->matriculaActiva($turma);
        $this->set = $this->propina($this->matricula, $plano, 1);
        $this->regras();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function metodoQueExige(string $nome = 'Transferência BAI'): MetodoPagamento
    {
        return MetodoPagamento::create(['nome' => $nome, 'tipo' => TipoMetodoPagamento::TRANSFERENCIA_BANCARIA, 'exige_comprovativo' => true]);
    }

    public function test_disco_privado_nao_tem_url_nem_esta_sob_public(): void
    {
        $disco = config('filesystems.disks.privado');

        $this->assertArrayNotHasKey('url', $disco);
        $this->assertStringStartsNotWith(public_path(), $disco['root']);
    }

    public function test_por_omissao_os_metodos_electronicos_exigem_comprovativo_e_os_manuais_nao(): void
    {
        foreach ([TipoMetodoPagamento::TRANSFERENCIA_BANCARIA, TipoMetodoPagamento::MULTICAIXA, TipoMetodoPagamento::TRANSFERENCIA_EXPRESS, TipoMetodoPagamento::CARTEIRA_DIGITAL] as $tipo) {
            $this->assertTrue($tipo->exigeComprovativoPorOmissao(), $tipo->label());
        }

        foreach ([TipoMetodoPagamento::NUMERARIO, TipoMetodoPagamento::TPA, TipoMetodoPagamento::OUTRO] as $tipo) {
            $this->assertFalse($tipo->exigeComprovativoPorOmissao(), $tipo->label());
        }
    }

    public function test_metodo_persiste_exige_comprovativo_na_criacao_e_actualizacao(): void
    {
        $metodo = MetodoPagamento::create(['nome' => 'Express', 'tipo' => TipoMetodoPagamento::TRANSFERENCIA_EXPRESS, 'exige_comprovativo' => false]);
        $this->assertFalse($metodo->fresh()->exige_comprovativo);

        $metodo->update(['exige_comprovativo' => true]);
        $this->assertTrue($metodo->fresh()->exige_comprovativo);
    }

    public function test_registar_com_comprovativo_guarda_sob_o_prefixo_do_tenant(): void
    {
        $metodo = $this->metodoQueExige();
        $ficheiro = UploadedFile::fake()->create('talão.pdf', 100, 'application/pdf');

        $this->actingAs($this->adminEscola())->post(route('financeiro.pagamentos.store', $this->matricula->id), [
            'metodo_pagamento_id' => $metodo->id,
            'valor' => '25000,00',
            'data_pagamento' => '2026-10-16',
            'modo_distribuicao' => 'automatica',
            'comprovativo' => $ficheiro,
        ])->assertRedirect();

        $pagamento = Pagamento::query()->sole();
        $this->assertNotNull($pagamento->comprovativo_path);
        $this->assertStringStartsWith("tenants/{$this->tenant->id}/financeiro/comprovativos/", $pagamento->comprovativo_path);
        $this->assertSame('talão.pdf', $pagamento->comprovativo_nome_original);
        Storage::disk('privado')->assertExists($pagamento->comprovativo_path);
    }

    public function test_sem_comprovativo_quando_o_metodo_exige_recusa_no_pedido(): void
    {
        $metodo = $this->metodoQueExige();

        $this->actingAs($this->adminEscola())
            ->postJson(route('financeiro.pagamentos.store', $this->matricula->id), [
                'metodo_pagamento_id' => $metodo->id,
                'valor' => '25000,00',
                'data_pagamento' => '2026-10-16',
                'modo_distribuicao' => 'automatica',
            ])
            ->assertJsonValidationErrors('comprovativo');
    }

    public function test_se_o_pagamento_falhar_o_ficheiro_nao_fica_orfao(): void
    {
        $metodo = $this->metodoQueExige();
        $ficheiro = UploadedFile::fake()->create('talão.pdf', 100, 'application/pdf');
        $falhar = true;
        Credito::creating(function () use (&$falhar) {
            if ($falhar) {
                throw new RuntimeException('Falha injectada: crédito');
            }
        });

        try {
            app(RegistarPagamentoAction::class)->executar($this->matricula->fresh(), new RegistarPagamentoDTO(
                metodoPagamentoId: $metodo->id,
                valor: Dinheiro::deUnidadesMenores(6_000_000),
                dataPagamento: CarbonImmutable::createFromFormat('!Y-m-d', '2026-10-16'),
                valorCredito: Dinheiro::deUnidadesMenores(0),
                comprovativo: $ficheiro,
            ));
            $this->fail('A falha devia propagar.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, Pagamento::query()->count());
        $this->assertSame([], Storage::disk('privado')->allFiles(), 'Sem ficheiro órfão.');
    }

    public function test_anexar_depois_e_ver_pela_rota_autenticada(): void
    {
        $admin = $this->adminEscola();
        $pagamento = $this->pagar($this->matricula, 2_500_000, [[$this->set, 2_500_000]]);
        $this->assertNull($pagamento->comprovativo_path);

        $this->actingAs($admin)->get(route('financeiro.pagamentos.comprovativo', $pagamento->id))->assertNotFound();

        $this->actingAs($admin)
            ->post(route('financeiro.pagamentos.comprovativo.anexar', $pagamento->id), ['comprovativo' => UploadedFile::fake()->create('talão.jpg', 100, 'image/jpeg')])
            ->assertSessionHas('success', 'Comprovativo anexado.');

        $pagamento->refresh();
        $this->assertNotNull($pagamento->comprovativo_path);
        Storage::disk('privado')->assertExists($pagamento->comprovativo_path);

        $this->actingAs($admin)->get(route('financeiro.pagamentos.comprovativo', $pagamento->id))->assertOk();
    }

    public function test_anexar_quando_ja_tem_e_recusado(): void
    {
        $pagamento = $this->pagar($this->matricula, 2_500_000, [[$this->set, 2_500_000]]);
        app(AnexarComprovativoAction::class)->executar($pagamento, UploadedFile::fake()->create('a.pdf', 100, 'application/pdf'));

        try {
            app(AnexarComprovativoAction::class)->executar($pagamento->fresh(), UploadedFile::fake()->create('b.pdf', 100, 'application/pdf'));
            $this->fail('Anexar um segundo comprovativo devia recusar.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('comprovativo', $e->errors());
        }
    }

    public function test_o_modelo_nao_substitui_o_comprovativo(): void
    {
        $pagamento = $this->pagamentoDirecto($this->matricula);
        $pagamento->forceFill([
            'comprovativo_path' => "tenants/{$this->tenant->id}/financeiro/comprovativos/x.pdf",
            'comprovativo_nome_original' => 'x.pdf',
            'comprovativo_mime_type' => 'application/pdf',
        ])->save();

        $this->expectException(LogicException::class);
        $pagamento->fresh()->forceFill(['comprovativo_path' => 'outro.pdf'])->save();
    }
}
