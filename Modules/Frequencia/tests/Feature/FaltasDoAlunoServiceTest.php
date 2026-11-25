<?php

namespace Modules\Frequencia\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Core\Contracts\ConsultaFaltasDoAluno;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Frequencia\Enums\EstadoAula;
use Modules\Frequencia\Enums\EstadoPresenca;
use Modules\Frequencia\Enums\TipoAula;
use Modules\Frequencia\Models\Aula;
use Modules\Frequencia\Models\Justificacao;
use Modules\Frequencia\Tests\Support\CenarioDeFrequencia;
use Tests\TestCase;

class FaltasDoAlunoServiceTest extends TestCase
{
    use CenarioDeFrequencia;
    use RefreshDatabase;

    private function resumo(array $c, int $aluno = 0): array
    {
        return app(ConsultaFaltasDoAluno::class)->resumoDaMatricula($c['alunos'][$aluno]->id);
    }

    private function justificar(Aula $aula, int $matriculaId): void
    {
        Justificacao::create(['registo_id' => $aula->registos()->where('matricula_id', $matriculaId)->value('id'), 'motivo' => 'Doença', 'justificada_em' => Carbon::now()]);
    }

    public function test_conta_so_faltas_e_separa_justificadas(): void
    {
        $c = $this->cenarioDeFrequencia();
        $a1 = $this->aulaComRegistos($c, '2026-03-02', [EstadoPresenca::FALTA]);
        $this->aulaComRegistos($c, '2026-03-09', [EstadoPresenca::FALTA]);
        $this->aulaComRegistos($c, '2026-03-16', [EstadoPresenca::ATRASO]);
        $this->aulaComRegistos($c, '2026-03-23', [EstadoPresenca::PRESENTE]);
        $this->justificar($a1, $c['alunos'][0]->id);

        $r = $this->resumo($c);

        $this->assertSame('aulas', $r['unidade']);
        $this->assertSame([2, 1, 1], [$r['total'], $r['justificadas'], $r['injustificadas']]);
        $this->assertTrue($r['com_chamadas']);
        $this->assertSame([0, 0, 0], array_values(array_diff_key($this->resumo($c, 1), ['unidade' => 1, 'por_disciplina' => 1, 'por_periodo' => 1, 'com_chamadas' => 1])));
    }

    public function test_com_chamadas_so_quando_ha_registos_de_aulas_leccionadas(): void
    {
        $c = $this->cenarioDeFrequencia();
        $this->assertFalse($this->resumo($c)['com_chamadas']);

        $this->aulaComRegistos($c, '2026-03-02', [], ['estado' => EstadoAula::NAO_LECCIONADA, 'motivo_nao_leccionada' => 'Greve']);
        $this->assertFalse($this->resumo($c)['com_chamadas']);

        $this->aulaComRegistos($c, '2026-03-09');
        $this->assertTrue($this->resumo($c)['com_chamadas']);
        $this->assertSame(0, $this->resumo($c)['total']);
    }

    public function test_aula_nao_leccionada_nao_conta(): void
    {
        $c = $this->cenarioDeFrequencia();
        $this->aulaComRegistos($c, '2026-03-02', [EstadoPresenca::FALTA], ['estado' => EstadoAula::NAO_LECCIONADA, 'motivo_nao_leccionada' => 'Greve']);

        $this->assertSame(0, $this->resumo($c)['total']);
    }

    public function test_por_disciplina_e_por_periodo(): void
    {
        $c = $this->cenarioDeFrequencia();
        $p1 = Periodo::create(['ano_lectivo_id' => $c['ano']->id, 'nome' => '1.º Trimestre', 'tipo' => TipoPeriodo::TRIMESTRE, 'numero' => 1, 'data_inicio' => '2026-01-01', 'data_fim' => '2026-04-30']);
        $p2 = Periodo::create(['ano_lectivo_id' => $c['ano']->id, 'nome' => '2.º Trimestre', 'tipo' => TipoPeriodo::TRIMESTRE, 'numero' => 2, 'data_inicio' => '2026-05-01', 'data_fim' => '2026-08-31']);
        $a1 = $this->aulaComRegistos($c, '2026-03-02', [EstadoPresenca::FALTA]);
        $this->aulaComRegistos($c, '2026-03-09', [EstadoPresenca::FALTA], ['disciplina_id' => $c['outraDisciplina']->id, 'horario_id' => $c['tempo2']->id]);
        $this->aulaComRegistos($c, '2026-05-04', [EstadoPresenca::FALTA]);
        $this->justificar($a1, $c['alunos'][0]->id);

        $r = $this->resumo($c);

        $this->assertSame(3, $r['total']);
        $this->assertSame(['total' => 2, 'justificadas' => 1, 'injustificadas' => 1], $r['por_disciplina'][$c['disciplina']->id]);
        $this->assertSame(['total' => 1, 'justificadas' => 0, 'injustificadas' => 1], $r['por_disciplina'][$c['outraDisciplina']->id]);
        $this->assertSame([
            ['periodo_id' => $p1->id, 'nome' => '1.º Trimestre', 'total' => 2, 'justificadas' => 1, 'injustificadas' => 1],
            ['periodo_id' => $p2->id, 'nome' => '2.º Trimestre', 'total' => 1, 'justificadas' => 0, 'injustificadas' => 1],
        ], $r['por_periodo']);
    }

    public function test_regime_dia_conta_dias(): void
    {
        $c = $this->cenarioDeFrequencia();
        $c['turma']->nivelAcademico->update(['etapa_ensino' => EtapaEnsinoEnum::PRIMARIO]);
        $this->aulaComRegistos($c, '2026-03-02', [EstadoPresenca::FALTA], ['tipo' => TipoAula::DIA, 'horario_id' => null, 'disciplina_id' => null]);

        $r = $this->resumo($c);

        $this->assertSame('dias', $r['unidade']);
        $this->assertSame(1, $r['total']);
        $this->assertSame([], $r['por_disciplina']);
    }

    public function test_sem_registos_ou_matricula_inexistente_devolve_zeros_e_sem_periodos_nao_rebenta(): void
    {
        $c = $this->cenarioDeFrequencia();

        $r = $this->resumo($c);
        $this->assertSame(['aulas', 0, 0, 0, [], [], false], [$r['unidade'], $r['total'], $r['justificadas'], $r['injustificadas'], $r['por_disciplina'], $r['por_periodo'], $r['com_chamadas']]);
        $this->assertSame(0, app(ConsultaFaltasDoAluno::class)->resumoDaMatricula(999999)['total']);
    }
}
