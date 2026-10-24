<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Enums\Estado;
use Modules\Financeiro\Models\PlanoPropinaAlvo;
use Modules\Financeiro\Services\ResolvePlanoAplicavel;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\ResolucaoDePlanosMemorizada;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Tests\TestCase;

class ResolucaoDePlanosMemorizadaTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use RefreshDatabase;

    private int $consultas = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::listen(function () {
            $this->consultas++;
        });
    }

    private function memoria(): ResolucaoDePlanosMemorizada
    {
        return new ResolucaoDePlanosMemorizada(app(ResolvePlanoAplicavel::class));
    }

    public function test_memoriza_por_turma_e_competencia(): void
    {
        $ano = $this->anoLectivo();
        $turma = $this->turma($ano, $this->nivel('N1'));
        $plano = $this->plano($ano, 'Geral');
        $memoria = $this->memoria();
        $memoria->sincronizar($ano->id, bloquear: false);

        $primeira = $memoria->paraTurma($turma, 2026, 9);
        $antes = $this->consultas;
        $segunda = $memoria->paraTurma($turma, 2026, 9);

        $this->assertSame($plano->id, $primeira->plano->id);
        $this->assertSame($primeira, $segunda);
        $this->assertSame($antes, $this->consultas, 'A segunda resolução da mesma turma e mês não vai à base.');
        $this->assertNotSame($primeira, $memoria->paraTurma($turma, 2026, 10));
    }

    public function test_os_meses_cobraveis_sao_a_uniao_ordenada_dos_planos_activos_do_ano(): void
    {
        $ano = $this->anoLectivo();
        $this->plano($ano, 'Jan–Jun', ['mes_inicio' => 1, 'mes_fim' => 6]);
        $this->plano($ano, 'Set–Dez', ['mes_inicio' => 9, 'mes_fim' => 12]);
        $this->plano($ano, 'Julho inactivo', ['mes_inicio' => 7, 'mes_fim' => 7, 'estado' => Estado::INATIVO->value]);

        $meses = $this->memoria()->mesesCobraveis($ano->id);

        $this->assertSame(
            ['2026-09', '2026-10', '2026-11', '2026-12', '2027-01', '2027-02', '2027-03', '2027-04', '2027-05', '2027-06'],
            array_map(fn (array $c) => sprintf('%04d-%02d', $c['ano'], $c['mes']), $meses),
        );
    }

    public function test_esquece_tudo_quando_um_plano_ou_os_seus_alvos_mudam(): void
    {
        $ano = $this->anoLectivo();
        $nivel = $this->nivel('N1');
        $turma = $this->turma($ano, $nivel);
        $plano = $this->plano($ano, 'Geral');
        $memoria = $this->memoria();

        $this->assertTrue($memoria->sincronizar($ano->id, bloquear: false), 'A primeira impressão conta como mudança.');
        $this->assertSame(2_500_000, $memoria->paraTurma($turma, 2026, 9)->plano->valor->unidadesMenores());
        $this->assertFalse($memoria->sincronizar($ano->id, bloquear: false), 'Nada mudou: a memória mantém-se.');

        $plano->forceFill(['valor' => Dinheiro::deUnidadesMenores(3_000_000)])->save();

        $this->assertTrue($memoria->sincronizar($ano->id, bloquear: false));
        $this->assertSame(3_000_000, $memoria->paraTurma($turma, 2026, 9)->plano->valor->unidadesMenores());

        PlanoPropinaAlvo::create(['plano_propina_id' => $plano->id, 'nivel_academico_id' => $nivel->id]);

        $this->assertTrue($memoria->sincronizar($ano->id, bloquear: false), 'Mudar só os alvos também invalida a memória.');
    }
}
