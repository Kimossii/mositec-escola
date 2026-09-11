<?php

namespace Modules\PlanoCurricular\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Curso\Models\Curso;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Http\Requests\DefinirPeriodosDisciplinaRequest;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularAnoLectivo;
use Modules\Turma\Models\NivelAcademico;
use Tests\TestCase;

class DefinirPeriodosDisciplinaRequestTest extends TestCase
{
    use RefreshDatabase;

    private function contexto(): array
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'C1', 'nome' => 'Curso A']);
        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'curso_id' => $curso->id, 'codigo' => 'PC1', 'nome' => 'Plano A']);
        $disciplinaRegistro = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'D1', 'nome' => 'Matemática']);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1]);
        $disciplinaDoPlano = $plano->disciplinas()->create(['disciplina_id' => $disciplinaRegistro->id, 'nivel_academico_id' => $nivel->id, 'tipo' => 0, 'ordem' => 1]);

        $anoLectivo = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026/2027', 'data_inicio' => '2026-02-01', 'data_fim' => '2026-11-30', 'estado' => EstadoAnoLectivo::ATIVO]);
        $periodo = Periodo::create(['ano_lectivo_id' => $anoLectivo->id, 'nome' => '1º Trimestre', 'tipo' => TipoPeriodo::TRIMESTRE, 'numero' => 1, 'data_inicio' => '2026-02-01', 'data_fim' => '2026-05-01']);
        $aplicacao = PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $anoLectivo->id]);

        $outroAnoLectivo = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2027/2028', 'data_inicio' => '2027-02-01', 'data_fim' => '2027-11-30', 'estado' => EstadoAnoLectivo::PLANEADO]);
        $periodoDeOutroAno = Periodo::create(['ano_lectivo_id' => $outroAnoLectivo->id, 'nome' => '1º Trimestre', 'tipo' => TipoPeriodo::TRIMESTRE, 'numero' => 1, 'data_inicio' => '2027-02-01', 'data_fim' => '2027-05-01']);

        return compact('plano', 'disciplinaDoPlano', 'aplicacao', 'periodo', 'periodoDeOutroAno');
    }

    private function requestComAplicacao(PlanoCurricularAnoLectivo $aplicacao): DefinirPeriodosDisciplinaRequest
    {
        $request = new DefinirPeriodosDisciplinaRequest;
        $request->setRouteResolver(fn () => tap(new Route('PUT', '/x', []), fn ($r) => $r->bind(new Request)->setParameter('planoCurricularAnoLectivo', $aplicacao)));

        return $request;
    }

    public function test_aceita_periodos_do_mesmo_ano_lectivo_da_aplicacao(): void
    {
        ['aplicacao' => $aplicacao, 'periodo' => $periodo] = $this->contexto();

        $validator = Validator::make(
            ['periodo_ids' => [$periodo->id]],
            $this->requestComAplicacao($aplicacao)->rules(),
        );

        $this->assertFalse($validator->fails());
    }

    public function test_rejeita_periodo_de_outro_ano_lectivo(): void
    {
        ['aplicacao' => $aplicacao, 'periodoDeOutroAno' => $periodoDeOutroAno] = $this->contexto();

        $validator = Validator::make(
            ['periodo_ids' => [$periodoDeOutroAno->id]],
            $this->requestComAplicacao($aplicacao)->rules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('periodo_ids.0', $validator->errors()->toArray());
    }

    public function test_aceita_conjunto_vazio(): void
    {
        ['aplicacao' => $aplicacao] = $this->contexto();

        $validator = Validator::make(
            ['periodo_ids' => []],
            $this->requestComAplicacao($aplicacao)->rules(),
        );

        $this->assertFalse($validator->fails());
    }
}
