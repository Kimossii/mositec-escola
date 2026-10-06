<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Core\Services\GeradorSequencia;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\TenantContext;
use Modules\Matricula\Models\MatriculaRegistoSequencia;
use Modules\Matricula\Services\GeradorNumeroRegistoMatriculaService;
use Modules\Tenant\Models\Tenant;
use Modules\Usuario\Models\MatriculaSequencia;
use Modules\Usuario\Services\GeradorMatriculaService;
use Tests\TestCase;

class GeradorSequenciaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $outro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function gerador(): GeradorSequencia
    {
        return app(GeradorSequencia::class);
    }

    private function gerarEmA(string $modelo = MatriculaSequencia::class): string
    {
        return $this->gerador()->gerar($modelo);
    }

    private function gerarEmB(string $modelo = MatriculaSequencia::class): string
    {
        return $this->noTenant($this->outro, fn () => $this->gerador()->gerar($modelo));
    }

    public function test_a_e_b_comecam_cada_um_em_0001_no_mesmo_ano(): void
    {
        $ano = now()->year;

        $this->assertSame("{$ano}-0001", $this->gerarEmA());
        $this->assertSame("{$ano}-0001", $this->gerarEmB());
        $this->assertSame("{$ano}-0002", $this->gerarEmA());
        $this->assertSame("{$ano}-0002", $this->gerarEmB());
    }

    public function test_intercalar_a_b_a_b_da_0001_0001_0002_0002(): void
    {
        $ano = now()->year;

        $numeros = [$this->gerarEmA(), $this->gerarEmB(), $this->gerarEmA(), $this->gerarEmB()];

        $this->assertSame(["{$ano}-0001", "{$ano}-0001", "{$ano}-0002", "{$ano}-0002"], $numeros);
    }

    public function test_a_sequencia_de_a_nao_altera_a_de_b(): void
    {
        $this->gerarEmA();
        $this->gerarEmA();
        $this->gerarEmA();
        $this->gerarEmB();

        $linhas = DB::table('matricula_sequencias')->orderBy('tenant_id')->get();

        $this->assertCount(2, $linhas);
        $this->assertSame($this->tenant->id, (int) $linhas[0]->tenant_id);
        $this->assertSame(3, (int) $linhas[0]->ultimo_numero);
        $this->assertSame($this->outro->id, (int) $linhas[1]->tenant_id);
        $this->assertSame(1, (int) $linhas[1]->ultimo_numero);

        $this->assertSame(3, MatriculaSequencia::firstOrFail()->ultimo_numero);
        $this->noTenant($this->outro, fn () => $this->assertSame(1, MatriculaSequencia::firstOrFail()->ultimo_numero));
    }

    public function test_sem_contexto_lanca_tenant_nao_resolvido_e_nao_cria_linhas(): void
    {
        app(TenantContext::class)->limpar();

        try {
            $this->gerador()->gerar(MatriculaSequencia::class);
            $this->fail('Devia lançar TenantNaoResolvido.');
        } catch (TenantNaoResolvido) {
            // esperado
        }

        $this->assertSame(0, DB::table('matricula_sequencias')->count());
        $this->assertSame(0, DB::table('matricula_registo_sequencias')->count());
    }

    public function test_vinte_chamadas_dao_vinte_numeros_distintos_e_consecutivos(): void
    {
        $ano = now()->year;
        $numeros = [];

        for ($i = 0; $i < 20; $i++) {
            $numeros[] = $this->gerarEmA();
        }

        $this->assertCount(20, array_unique($numeros));
        for ($i = 1; $i <= 20; $i++) {
            $this->assertSame(sprintf('%d-%04d', $ano, $i), $numeros[$i - 1]);
        }
    }

    public function test_o_upsert_e_idempotente_uma_so_linha_por_tenant_e_ano(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->gerarEmA();
        }

        $this->assertSame(1, DB::table('matricula_sequencias')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_corrida_do_primeiro_numero_linha_pre_existente_e_absorvida_pelo_upsert(): void
    {
        $ano = now()->year;

        // Simula outro processo que inseriu a linha (tenant, ano) entre a leitura e a escrita.
        DB::table('matricula_sequencias')->insert([
            'tenant_id' => $this->tenant->id, 'ano' => $ano, 'ultimo_numero' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame("{$ano}-0001", $this->gerarEmA());
        $this->assertSame(1, DB::table('matricula_sequencias')->count());
    }

    public function test_a_bd_recusa_duas_linhas_com_o_mesmo_tenant_e_ano_mas_aceita_em_tenants_diferentes(): void
    {
        foreach (['matricula_sequencias', 'matricula_registo_sequencias'] as $tabela) {
            $linha = fn (int $tenantId) => [
                'tenant_id' => $tenantId, 'ano' => 2030, 'ultimo_numero' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ];

            DB::table($tabela)->insert($linha($this->tenant->id));
            DB::table($tabela)->insert($linha($this->outro->id));
            $this->assertSame(2, DB::table($tabela)->where('ano', 2030)->count());

            try {
                DB::table($tabela)->insert($linha($this->tenant->id));
                $this->fail("{$tabela} devia recusar (tenant_id, ano) duplicado.");
            } catch (QueryException) {
                // esperado
            }
        }
    }

    public function test_ano_novo_recomeca_em_0001_para_o_mesmo_tenant(): void
    {
        Carbon::setTestNow('2031-12-31 23:59:00');
        $this->assertSame('2031-0001', $this->gerarEmA());
        $this->assertSame('2031-0002', $this->gerarEmA());

        Carbon::setTestNow('2032-01-01 00:01:00');
        $this->assertSame('2032-0001', $this->gerarEmA());
        $this->assertSame('2032-0002', $this->gerarEmA());

        $this->assertSame(2, DB::table('matricula_sequencias')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_so_aceita_as_sequencias_conhecidas(): void
    {
        foreach (['matricula_sequencias; DROP TABLE users', \stdClass::class, Tenant::class, ''] as $invalido) {
            try {
                $this->gerador()->gerar($invalido);
                $this->fail("Devia recusar '{$invalido}'.");
            } catch (InvalidArgumentException) {
                // esperado
            }
        }

        $this->assertSame(0, DB::table('matricula_sequencias')->count());
    }

    public function test_cada_servico_usa_a_sua_tabela_e_mantem_o_formato(): void
    {
        $ano = now()->year;

        $this->assertSame("{$ano}-0001", app(GeradorMatriculaService::class)->gerar());
        $this->assertSame("{$ano}-0002", (new GeradorMatriculaService())->gerar());
        $this->assertMatchesRegularExpression('/^\d{4}-\d{4}$/', app(GeradorNumeroRegistoMatriculaService::class)->gerar());

        $this->assertSame(2, MatriculaSequencia::firstOrFail()->ultimo_numero);
        $this->assertSame(1, MatriculaRegistoSequencia::firstOrFail()->ultimo_numero);
    }
}
