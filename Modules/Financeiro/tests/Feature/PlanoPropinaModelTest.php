<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Enums\Estado;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\TenantContext;
use Modules\Financeiro\Enums\Periodicidade;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\PlanoPropinaAlvo;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Tests\TestCase;

class PlanoPropinaModelTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use RefreshDatabase;

    public function test_cria_plano_com_valor_em_unidades_menores_e_descricoes_sincronizadas(): void
    {
        $plano = $this->plano($this->anoLectivo(), 'Propina Mensal', [
            'periodicidade' => Periodicidade::TRIMESTRAL, 'intervalo_meses' => 3,
        ]);

        $plano->refresh();
        $this->assertSame($this->tenant->id, $plano->tenant_id);
        $this->assertSame(2_500_000, $plano->valor->unidadesMenores());
        $this->assertSame(2_500_000, (int) DB::table('planos_propina')->where('id', $plano->id)->value('valor'));
        $this->assertSame(Periodicidade::TRIMESTRAL, $plano->periodicidade);
        $this->assertSame('Trimestral', $plano->periodicidade_descricao);
        $this->assertSame('Ativo', $plano->estado_descricao);
        $this->assertSame(2_500_000, $plano->toArray()['valor']);
    }

    public function test_competencias_e_periodos_usam_o_inicio_do_ano_lectivo(): void
    {
        $plano = $this->plano($this->anoLectivo('2026/2027', '2026-09-01'), 'P', [
            'periodicidade' => Periodicidade::TRIMESTRAL, 'intervalo_meses' => 3,
        ]);

        $this->assertSame(10, $plano->totalMeses());
        $this->assertCount(10, $plano->competencias());
        $this->assertSame(['ano' => 2026, 'mes' => 9], $plano->competencias()[0]);
        $this->assertSame([3, 3, 3, 1], array_column($plano->periodos(), 'meses'));
    }

    public function test_jan_a_dez_comeca_no_ano_seguinte_ao_inicio_do_ano_lectivo(): void
    {
        $plano = $this->plano($this->anoLectivo(), 'P', ['mes_inicio' => 1, 'mes_fim' => 12]);

        $this->assertSame(['ano' => 2027, 'mes' => 1], $plano->competencias()[0]);
    }

    public function test_nome_unico_por_ano_lectivo_no_tenant(): void
    {
        $ano = $this->anoLectivo('2026/2027');
        $outroAno = $this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31');
        $this->plano($ano, 'Propina');

        $this->plano($outroAno, 'Propina'); // outro ano: permitido
        $this->assertSame(2, PlanoPropina::count());

        $this->expectException(QueryException::class);
        $this->plano($ano, 'Propina');
    }

    public function test_mesmo_nome_e_ano_em_tenants_diferentes_e_permitido(): void
    {
        $this->plano($this->anoLectivo(), 'Propina');
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        $this->noTenant($outro, fn () => $this->plano($this->anoLectivo(), 'Propina'));

        $this->assertSame(1, PlanoPropina::count());
        $this->assertSame(1, $this->noTenant($outro, fn () => PlanoPropina::count()));
    }

    public function test_substituir_alvos_troca_os_alvos_e_so_activos_entram_no_scope(): void
    {
        $ano = $this->anoLectivo();
        $nivel = $this->nivel('N1');
        $curso = $this->curso('C1');
        $turno = $this->turno('Noite');
        $plano = $this->plano($ano, 'A', [], [['nivel' => $nivel]]);

        $plano->substituirAlvos([
            ['nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id],
            ['curso_id' => $curso->id, 'turno_id' => $turno->id],
        ]);

        $this->assertSame(2, $plano->alvos()->count());
        $alvo = $plano->alvos()->orderBy('id')->first();
        $this->assertSame($this->tenant->id, $alvo->tenant_id);
        $this->assertSame($nivel->id, $alvo->nivel_academico_id);
        $this->assertNull($alvo->turno_id);
        $this->assertNull($alvo->turma_id);

        $this->plano($ano, 'B', ['estado' => Estado::INATIVO->value]);
        $this->assertSame(['A'], PlanoPropina::activos()->pluck('nome')->all());
        $this->assertSame(2, PlanoPropina::count());
    }

    public function test_alvo_guarda_turno_e_turma(): void
    {
        $ano = $this->anoLectivo();
        $turma = $this->turma($ano, $this->nivel('N1'));
        $plano = $this->plano($ano, 'A', [], [['turma' => $turma]]);

        $alvo = $plano->alvos()->first();
        $this->assertSame($turma->id, $alvo->turma_id);
        $this->assertSame($turma->id, $alvo->turma->id);
    }

    public function test_eliminar_o_plano_apaga_os_seus_alvos(): void
    {
        $plano = $this->plano($this->anoLectivo(), 'A', [], [['nivel' => $this->nivel('N1')]]);
        $this->assertSame(1, PlanoPropinaAlvo::count());

        $plano->delete();

        $this->assertSame(0, PlanoPropinaAlvo::count());
    }

    public function test_sem_contexto_de_tenant_a_escrita_lanca_tenant_nao_resolvido(): void
    {
        app(TenantContext::class)->limpar();

        $this->expectException(TenantNaoResolvido::class);

        (new PlanoPropina())->save();
    }

    public function test_sem_contexto_de_tenant_a_escrita_de_um_alvo_lanca_tenant_nao_resolvido(): void
    {
        app(TenantContext::class)->limpar();

        $this->expectException(TenantNaoResolvido::class);

        (new PlanoPropinaAlvo())->save();
    }
}
