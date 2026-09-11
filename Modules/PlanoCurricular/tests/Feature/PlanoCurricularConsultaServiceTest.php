<?php

namespace Modules\PlanoCurricular\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Curso\Models\Curso;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Services\PlanoCurricularConsultaService;
use Modules\Turma\Models\NivelAcademico;
use Tests\TestCase;

class PlanoCurricularConsultaServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_listar_devolve_apenas_planos_do_estabelecimento_actual(): void
    {
        $atual = Estabelecimento::create(['nome' => 'Escola Actual', 'tipo' => 1, 'is_active' => true]);
        $outra = Estabelecimento::create(['nome' => 'Outra Escola', 'tipo' => 1, 'is_active' => false]);

        $cursoAtual = Curso::create(['estabelecimento_id' => $atual->id, 'codigo' => 'C1', 'nome' => 'Curso Actual']);
        $cursoOutro = Curso::create(['estabelecimento_id' => $outra->id, 'codigo' => 'C1', 'nome' => 'Curso Outro']);

        $planoAtual = PlanoCurricular::create(['estabelecimento_id' => $atual->id, 'curso_id' => $cursoAtual->id, 'codigo' => 'PC1', 'nome' => 'Plano Actual']);
        PlanoCurricular::create(['estabelecimento_id' => $outra->id, 'curso_id' => $cursoOutro->id, 'codigo' => 'PC1', 'nome' => 'Plano Outro']);

        $planos = (new PlanoCurricularConsultaService())->listar();

        $this->assertCount(1, $planos);
        $this->assertSame($planoAtual->id, $planos->first()->id);
    }

    public function test_opcoes_formulario_filtra_pelo_estabelecimento_actual(): void
    {
        $atual = Estabelecimento::create(['nome' => 'Escola Actual', 'tipo' => 1, 'is_active' => true]);
        $outra = Estabelecimento::create(['nome' => 'Outra Escola', 'tipo' => 1, 'is_active' => false]);

        $cursoAtual = Curso::create(['estabelecimento_id' => $atual->id, 'codigo' => 'C1', 'nome' => 'Curso Actual']);
        Curso::create(['estabelecimento_id' => $outra->id, 'codigo' => 'C1', 'nome' => 'Curso Outro']);

        $disciplinaAtual = Disciplina::create(['estabelecimento_id' => $atual->id, 'codigo' => 'D1', 'nome' => 'Disciplina Actual']);
        Disciplina::create(['estabelecimento_id' => $outra->id, 'codigo' => 'D1', 'nome' => 'Disciplina Outra']);

        $nivelAtual = NivelAcademico::create(['estabelecimento_id' => $atual->id, 'codigo' => 'N1', 'nome' => 'Nível Actual', 'ordem' => 1]);
        NivelAcademico::create(['estabelecimento_id' => $outra->id, 'codigo' => 'N1', 'nome' => 'Nível Outro', 'ordem' => 1]);

        $anoAtual = AnoLectivo::create(['estabelecimento_id' => $atual->id, 'nome' => '2026', 'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        AnoLectivo::create(['estabelecimento_id' => $outra->id, 'nome' => '2026', 'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31', 'estado' => EstadoAnoLectivo::ATIVO]);

        $opcoes = (new PlanoCurricularConsultaService())->opcoesFormulario();

        $this->assertCount(1, $opcoes['cursos']);
        $this->assertSame($cursoAtual->id, $opcoes['cursos']->first()->id);

        $this->assertCount(1, $opcoes['disciplinas']);
        $this->assertSame($disciplinaAtual->id, $opcoes['disciplinas']->first()->id);

        $this->assertCount(1, $opcoes['niveisAcademicos']);
        $this->assertSame($nivelAtual->id, $opcoes['niveisAcademicos']->first()->id);

        $this->assertCount(1, $opcoes['anosLectivos']);
        $this->assertSame($anoAtual->id, $opcoes['anosLectivos']->first()->id);
    }
}
