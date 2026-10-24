<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Enums\Estado;
use Modules\Financeiro\Services\ResolvePlanoAplicavel;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Tests\TestCase;

class ResolvePlanoAplicavelTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use RefreshDatabase;

    private function resolver(): ResolvePlanoAplicavel
    {
        return app(ResolvePlanoAplicavel::class);
    }

    public function test_sem_planos_nao_ha_plano(): void
    {
        $ano = $this->anoLectivo();
        $turma = $this->turma($ano, $this->nivel('N1'));

        $resultado = $this->resolver()->paraTurma($turma);

        $this->assertFalse($resultado->temPlano());
        $this->assertNull($resultado->plano);
        $this->assertFalse($resultado->conflito);
    }

    public function test_plano_geral_aplica_se_a_todas_as_turmas_do_ano(): void
    {
        $ano = $this->anoLectivo();
        $geral = $this->plano($ano, 'Geral');

        $resultado = $this->resolver()->paraTurma($this->turma($ano, $this->nivel('N1')));

        $this->assertTrue($resultado->temPlano());
        $this->assertSame($geral->id, $resultado->plano->id);
    }

    public function test_plano_de_nivel_so_aplica_ao_nivel(): void
    {
        $ano = $this->anoLectivo();
        $n1 = $this->nivel('N1');
        $n2 = $this->nivel('N2');
        $plano = $this->plano($ano, 'Nivel 1', [], [['nivel' => $n1]]);

        $this->assertSame($plano->id, $this->resolver()->paraTurma($this->turma($ano, $n1))->plano->id);
        $this->assertFalse($this->resolver()->paraTurma($this->turma($ano, $n2))->temPlano());
    }

    public function test_plano_de_curso_nao_aplica_a_turma_sem_curso(): void
    {
        $ano = $this->anoLectivo();
        $n1 = $this->nivel('N1');
        $curso = $this->curso('C1');
        $porCurso = $this->plano($ano, 'Por Curso', [], [['curso' => $curso]]);

        $this->assertFalse($this->resolver()->paraTurma($this->turma($ano, $n1))->temPlano());
        $this->assertSame($porCurso->id, $this->resolver()->paraTurma($this->turma($ano, $n1, $curso))->plano->id);
    }

    public function test_plano_de_turno_nao_aplica_a_turma_sem_turno(): void
    {
        $ano = $this->anoLectivo();
        $n1 = $this->nivel('N1');
        $noite = $this->turno('Noite');
        $plano = $this->plano($ano, 'Noite', [], [['turno' => $noite]]);

        $this->assertFalse($this->resolver()->paraTurma($this->turma($ano, $n1))->temPlano());
        $this->assertFalse($this->resolver()->paraTurma($this->turma($ano, $n1, null, $this->turno('Manha')))->temPlano());
        $this->assertSame($plano->id, $this->resolver()->paraTurma($this->turma($ano, $n1, null, $noite))->plano->id);
    }

    public function test_curso_vence_nivel_na_mesma_turma(): void
    {
        $ano = $this->anoLectivo();
        $n1 = $this->nivel('N1');
        $curso = $this->curso('C1');
        $this->plano($ano, 'Por Nivel', [], [['nivel' => $n1]]);
        $porCurso = $this->plano($ano, 'Por Curso', [], [['curso' => $curso]]);

        $resultado = $this->resolver()->paraTurma($this->turma($ano, $n1, $curso));

        $this->assertTrue($resultado->temPlano());
        $this->assertSame($porCurso->id, $resultado->plano->id);
    }

    public function test_nivel_mais_turno_vence_so_curso(): void
    {
        $ano = $this->anoLectivo();
        $n1 = $this->nivel('N1');
        $curso = $this->curso('C1');
        $noite = $this->turno('Noite');
        $this->plano($ano, 'Por Curso', [], [['curso' => $curso]]);
        $nivelNoite = $this->plano($ano, 'Nivel e Noite', [], [['nivel' => $n1, 'turno' => $noite]]);

        $this->assertSame($nivelNoite->id, $this->resolver()->paraTurma($this->turma($ano, $n1, $curso, $noite))->plano->id);
    }

    public function test_cada_um_dos_nove_niveis_vence_o_de_baixo(): void
    {
        $ano = $this->anoLectivo();
        $n1 = $this->nivel('N1');
        $curso = $this->curso('C1');
        $noite = $this->turno('Noite');
        $turma = $this->turma($ano, $n1, $curso, $noite);

        // Do mais específico para o menos: cada um, quando é o único mais específico presente, ganha.
        $planos = [
            'turma' => $this->plano($ano, 'P1 Turma', [], [['turma' => $turma]]),
            'curso+nivel+turno' => $this->plano($ano, 'P2', [], [['curso' => $curso, 'nivel' => $n1, 'turno' => $noite]]),
            'curso+nivel' => $this->plano($ano, 'P3', [], [['curso' => $curso, 'nivel' => $n1]]),
            'curso+turno' => $this->plano($ano, 'P4', [], [['curso' => $curso, 'turno' => $noite]]),
            'nivel+turno' => $this->plano($ano, 'P5', [], [['nivel' => $n1, 'turno' => $noite]]),
            'curso' => $this->plano($ano, 'P6', [], [['curso' => $curso]]),
            'nivel' => $this->plano($ano, 'P7', [], [['nivel' => $n1]]),
            'turno' => $this->plano($ano, 'P8', [], [['turno' => $noite]]),
            'geral' => $this->plano($ano, 'P9'),
        ];

        foreach ($planos as $nome => $esperado) {
            $this->assertSame($esperado->id, $this->resolver()->paraTurma($turma)->plano->id, "deve ganhar: {$nome}");
            $esperado->update(['estado' => Estado::INATIVO->value]);
        }

        $this->assertFalse($this->resolver()->paraTurma($turma)->temPlano());
    }

    public function test_turma_especifica_so_aplica_a_essa_turma(): void
    {
        $ano = $this->anoLectivo();
        $n1 = $this->nivel('N1');
        $a = $this->turma($ano, $n1);
        $b = $this->turma($ano, $n1);
        $geral = $this->plano($ano, 'Geral');
        $daTurma = $this->plano($ano, 'So A', [], [['turma' => $a]]);

        $this->assertSame($daTurma->id, $this->resolver()->paraTurma($a)->plano->id);
        $this->assertSame($geral->id, $this->resolver()->paraTurma($b)->plano->id);
    }

    public function test_empate_real_entre_planos_distintos_e_conflito(): void
    {
        $ano = $this->anoLectivo();
        $n1 = $this->nivel('N1');
        // O Request impede este estado ao gravar; cria-se directamente no modelo
        // (por exemplo, um plano reactivado depois) para provar a rede de segurança.
        $a = $this->plano($ano, 'Nivel A', [], [['nivel' => $n1]]);
        $b = $this->plano($ano, 'Nivel B', [], [['nivel' => $n1]]);

        $resultado = $this->resolver()->paraTurma($this->turma($ano, $n1));

        $this->assertFalse($resultado->temPlano());
        $this->assertTrue($resultado->conflito);
        $this->assertNull($resultado->plano);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_map(fn ($p) => $p->id, $resultado->candidatos));
    }

    public function test_dois_planos_distintos_com_a_mesma_turma_alvo_sao_conflito(): void
    {
        $ano = $this->anoLectivo();
        $turma = $this->turma($ano, $this->nivel('N1'));
        $this->plano($ano, 'Turma A', [], [['turma' => $turma]]);
        $this->plano($ano, 'Turma B', [], [['turma' => $turma]]);

        $resultado = $this->resolver()->paraTurma($turma);

        $this->assertTrue($resultado->conflito);
        $this->assertNull($resultado->plano);
    }

    public function test_um_plano_com_dois_alvos_que_casam_a_mesma_turma_nao_e_conflito(): void
    {
        $ano = $this->anoLectivo();
        $n1 = $this->nivel('N1');
        $turma = $this->turma($ano, $n1);
        $plano = $this->plano($ano, 'Dois Alvos', [], [['turma' => $turma], ['turma' => $turma]]);

        $resultado = $this->resolver()->paraTurma($turma);

        $this->assertFalse($resultado->conflito);
        $this->assertSame($plano->id, $resultado->plano->id);
    }

    public function test_plano_inactivo_e_ignorado(): void
    {
        $ano = $this->anoLectivo();
        $n1 = $this->nivel('N1');
        $geral = $this->plano($ano, 'Geral');
        $this->plano($ano, 'Nivel', ['estado' => Estado::INATIVO->value], [['nivel' => $n1]]);

        $this->assertSame($geral->id, $this->resolver()->paraTurma($this->turma($ano, $n1))->plano->id);
    }

    public function test_planos_de_outro_ano_lectivo_sao_ignorados(): void
    {
        $a = $this->anoLectivo('2026/2027');
        $b = $this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31');
        $this->plano($b, 'Geral B');

        $this->assertFalse($this->resolver()->paraTurma($this->turma($a, $this->nivel('N1')))->temPlano());
    }

    public function test_plano_com_varios_alvos_usa_o_alvo_que_casa_melhor(): void
    {
        $ano = $this->anoLectivo();
        $n1 = $this->nivel('N1');
        $n2 = $this->nivel('N2');
        $curso = $this->curso('C1');
        $multi = $this->plano($ano, 'Multi', [], [['nivel' => $n2], ['nivel' => $n1, 'curso' => $curso]]);
        $geral = $this->plano($ano, 'Geral');

        // N1+C1 casa o alvo mais específico do multi (curso+nível) e vence o geral.
        $this->assertSame($multi->id, $this->resolver()->paraTurma($this->turma($ano, $n1, $curso))->plano->id);
        // N2 casa o alvo só de nível, também acima do geral.
        $this->assertSame($multi->id, $this->resolver()->paraTurma($this->turma($ano, $n2))->plano->id);
        // N1 sem curso não casa nenhum alvo do multi: cai no geral.
        $this->assertSame($geral->id, $this->resolver()->paraTurma($this->turma($ano, $n1))->plano->id);
    }

    public function test_fases_de_preco_a_competencia_escolhe_o_plano_certo(): void
    {
        $ano = $this->anoLectivo(); // começa em 2026-09-01
        $n1 = $this->nivel('N1');
        $curso = $this->curso('C1');
        $fase1 = $this->plano($ano, 'Fase 1', ['mes_inicio' => 9, 'mes_fim' => 12], [['nivel' => $n1, 'curso' => $curso]]);
        $fase2 = $this->plano($ano, 'Fase 2', ['mes_inicio' => 1, 'mes_fim' => 6], [['nivel' => $n1, 'curso' => $curso]]);
        $turma = $this->turma($ano, $n1, $curso);

        $this->assertSame($fase1->id, $this->resolver()->paraTurma($turma, ['ano' => 2026, 'mes' => 10])->plano->id);
        $this->assertSame($fase2->id, $this->resolver()->paraTurma($turma, ['ano' => 2027, 'mes' => 2])->plano->id);
        $this->assertFalse($this->resolver()->paraTurma($turma, ['ano' => 2027, 'mes' => 7])->temPlano()); // fora de ambas
        $this->assertTrue($this->resolver()->paraTurma($turma)->conflito); // sem competência o tempo é ignorado
    }

    public function test_com_competencia_o_mais_especifico_so_ganha_nos_meses_em_que_existe(): void
    {
        $ano = $this->anoLectivo();
        $n1 = $this->nivel('N1');
        $geral = $this->plano($ano, 'Geral Ano Todo');
        $nivelSoNoInicio = $this->plano($ano, 'Nivel Set-Dez', ['mes_inicio' => 9, 'mes_fim' => 12], [['nivel' => $n1]]);
        $turma = $this->turma($ano, $n1);

        $this->assertSame($nivelSoNoInicio->id, $this->resolver()->paraTurma($turma, ['ano' => 2026, 'mes' => 11])->plano->id);
        $this->assertSame($geral->id, $this->resolver()->paraTurma($turma, ['ano' => 2027, 'mes' => 3])->plano->id);
    }
}
